<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationDeleted;
use App\Events\ConversationPreferencesUpdated;
use App\Events\ConversationRead;
use App\Events\TypingIndicator;
use App\Http\Controllers\Controller;
use App\Http\Requests\MarkConversationReadRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Requests\UpdateConversationPreferencesRequest;
use App\Http\Requests\UpdateConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Services\ConversationParticipantService;
use App\Services\ConversationService;
use App\Services\SystemMessageService;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        // The viewer's own participant row, joined rather than asked about
        // per conversation: it's what the order below is decided by.
        $conversations = Conversation::visible()
            ->join('conversation_participants as viewer', function (JoinClause $join) use ($userId): void {
                $join->on('viewer.conversation_id', '=', 'conversations.id')
                    ->where('viewer.user_id', '=', $userId);
            })
            ->select('conversations.*')
            ->with(['lastMessage.sender', 'participants.user'])
            // Pinned first, then the most recently active. For a group the
            // viewer has left, activity stops when they left (LEAST ignores
            // the NULL left_at of everyone still in), so the order can't give
            // away that the group carried on without them.
            ->orderByRaw('viewer.pinned_at IS NULL')
            ->orderByRaw('COALESCE(LEAST(conversations.last_message_at, viewer.left_at), conversations.created_at) DESC')
            ->orderByDesc('conversations.id')
            ->get();

        return ConversationResource::collection($conversations);
    }

    public function show(Request $request, Conversation $conversation): ConversationResource|JsonResponse
    {
        if ($conversation->deleted_at) {
            return response()->json(['data' => ['message' => 'Conversation not found']], 404);
        }

        $isParticipant = $conversation->participants()->where('user_id', $request->user()->id)->exists();

        if (! $isParticipant) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $conversation->load('participants.user');

        return new ConversationResource($conversation);
    }

    /**
     * Admin-only: deletes a group for every member. Not supported for
     * direct conversations (see ConversationPolicy::delete()).
     */
    public function destroy(
        Request $request,
        Conversation $conversation,
        ConversationService $conversations,
    ): Response|JsonResponse {
        if (! $request->user()->can('delete', $conversation)) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $conversations->deleteGroup($conversation);
        broadcast(new ConversationDeleted($conversation));

        return response()->noContent();
    }

    public function store(
        StoreConversationRequest $request,
        ConversationService $conversations,
        SystemMessageService $systemMessages,
    ): JsonResponse {
        $type = $request->input('type', 'direct');

        if ($type === 'direct') {
            $other = User::findOrFail($request->input('participant_ids')[0]);
            $conversation = $conversations->findOrCreateDirect($request->user(), $other);
            $conversation->load(['participants.user', 'lastMessage.sender']);

            return (new ConversationResource($conversation))
                ->response()
                ->setStatusCode($conversation->wasRecentlyCreated ? 201 : 200);
        }

        $conversation = DB::transaction(function () use ($request): Conversation {
            $conversation = Conversation::create([
                'created_by_user_id' => $request->user()->id,
                'type' => $request->input('type', 'direct'),
                'title' => $request->input('title'),
            ]);

            $conversation->participants()->create([
                'user_id' => $request->user()->id,
                'role' => 'admin',
                'joined_at' => now(),
                'conversation_id' => $conversation->id,
            ]);

            foreach (array_diff($request->input('participant_ids', []), [$request->user()->id]) as $userId) {
                $conversation->participants()->create([
                    'user_id' => $userId,
                    'role' => 'participant',
                    'joined_at' => now(),
                    'conversation_id' => $conversation->id,
                ]);
            }

            return $conversation;
        });

        $systemMessages->record($conversation, 'group_created', [
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
        ]);

        $conversation->load(['participants.user', 'lastMessage.sender']);

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateConversationRequest $request,
        Conversation $conversation,
        ConversationParticipantService $service,
    ): ConversationResource {
        $service->rename($conversation, $request->user(), $request->input('title'));
        $conversation->load(['participants.user', 'lastMessage.sender']);

        return new ConversationResource($conversation);
    }

    public function typing(Request $request, Conversation $conversation): Response
    {
        $isParticipant = $conversation->participants()->where('user_id', $request->user()->id)->exists();

        if (! $isParticipant) {
            return response()->noContent(403);
        }

        broadcast(new TypingIndicator($conversation->id, $request->user()));

        return response()->noContent();
    }

    /**
     * Moves the viewer's read pointer forward — to `message_id`, or to the
     * newest message without one — and tells the conversation (the
     * ConversationRead event) so the others' "Sent" can become "Seen".
     *
     * The pointer never moves backwards: a request reporting less than it
     * already records (a slower tab, a late retry) changes nothing and
     * broadcasts nothing. That's one conditional UPDATE, so two requests
     * racing each other can't undo one another either.
     *
     * A member who left is refused: their history is frozen at the moment
     * they left, and moving the pointer on would mark as read — and tell the
     * group they'd read — messages they can't see.
     */
    public function markAsRead(MarkConversationReadRequest $request, Conversation $conversation): Response|JsonResponse
    {
        if ($conversation->deleted_at) {
            return response()->json(['data' => ['message' => 'Conversation not found']], 404);
        }

        $participant = $conversation->participants()->where('user_id', $request->user()->id)->first();

        if (! $participant || $participant->left_at !== null) {
            return response()->noContent(403);
        }

        $messageId = $request->validated('message_id');

        if ($messageId !== null && ! $conversation->messages()->whereKey($messageId)->exists()) {
            throw ValidationException::withMessages([
                'message_id' => 'That message isn\'t in this conversation.',
            ]);
        }

        $readUpTo = $messageId ?? $conversation->messages()->orderByDesc('id')->value('id');

        if ($readUpTo === null) {
            return response()->noContent();
        }

        $advanced = ConversationParticipant::query()
            ->whereKey($participant->id)
            ->where(fn ($query) => $query
                ->whereNull('last_read_message_id')
                ->orWhere('last_read_message_id', '<', $readUpTo))
            ->update([
                'last_read_message_id' => $readUpTo,
                'last_read_at' => now(),
            ]);

        if ($advanced > 0) {
            broadcast(new ConversationRead($participant->refresh()));
        }

        return response()->noContent();
    }

    /**
     * Pins, mutes or archives the conversation for the viewer, or undoes
     * it — their own view of it, which nobody else sees. Allowed for a group
     * they've left, too: archiving one is a way to tidy it away.
     *
     * Archiving takes a conversation off the main list, so it unpins it;
     * pinning one puts it back on the list, so it unarchives it. Switching on
     * something already on keeps the time it was first switched on.
     */
    public function updatePreferences(UpdateConversationPreferencesRequest $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->deleted_at) {
            return response()->json(['data' => ['message' => 'Conversation not found']], 404);
        }

        $participant = $conversation->participants()->where('user_id', $request->user()->id)->first();

        if (! $participant) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $changes = [];
        foreach (['pinned' => 'pinned_at', 'muted' => 'muted_at', 'archived' => 'archived_at'] as $key => $column) {
            if ($request->has($key)) {
                $changes[$column] = $request->boolean($key) ? ($participant->{$column} ?? now()) : null;
            }
        }

        if (($changes['archived_at'] ?? null) !== null) {
            $changes['pinned_at'] = null;
        } elseif (($changes['pinned_at'] ?? null) !== null) {
            $changes['archived_at'] = null;
        }

        $participant->fill($changes)->save();

        if ($participant->wasChanged()) {
            broadcast(new ConversationPreferencesUpdated($participant));
        }

        return response()->json(['data' => [
            'pinned_at' => $participant->pinned_at,
            'muted_at' => $participant->muted_at,
            'archived_at' => $participant->archived_at,
        ]]);
    }

    /**
     * Where everyone still in the conversation has read up to — what turns
     * "Sent" into "Seen" and fills the group's "Seen by" list. Pointers only:
     * the client compares them with its own messages' ids.
     *
     * For current members only. Someone who left sees the conversation as it
     * was when they left, and how far the others have read since isn't part
     * of that.
     */
    public function reads(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->deleted_at) {
            return response()->json(['data' => ['message' => 'Conversation not found']], 404);
        }

        $isActiveParticipant = $conversation->participants()
            ->where('user_id', $request->user()->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isActiveParticipant) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $pointers = $conversation->participants()
            ->active()
            ->get(['user_id', 'last_read_message_id', 'last_read_at'])
            ->map(fn (ConversationParticipant $participant): array => [
                'user_id' => $participant->user_id,
                'last_read_message_id' => $participant->last_read_message_id,
                'last_read_at' => $participant->last_read_at,
            ]);

        return response()->json(['data' => $pointers->values()]);
    }
}
