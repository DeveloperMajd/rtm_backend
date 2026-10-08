<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavedMessagesRequest;
use App\Http\Resources\SavedMessageResource;
use App\Models\Message;
use App\Models\SavedMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Saved messages: a private list of messages someone wants to find again,
 * their own or anyone's. Nothing about it reaches anyone else, and nothing
 * is broadcast: whether a message is saved is the viewer's alone, so it
 * isn't on MessageResource either, whose broadcast copy everyone shares.
 */
class SavedMessageController extends Controller
{
    /**
     * The viewer's saved messages, most recently saved first, up to `limit`
     * at a time (30 by default, 50 at most) and saved before `before_id`,
     * with the cursor for the next page.
     *
     * Only what's still there for them (Message::saveableBy): a conversation
     * they left or were removed from, or that was deleted, keeps its saved
     * messages out of the list, as does a message deleted since. The rows
     * stay, and show again if they're added back.
     */
    public function index(SavedMessagesRequest $request): AnonymousResourceCollection
    {
        $viewer = $request->user();
        $limit = max(1, min((int) $request->query('limit', 30), 50));
        $beforeId = $request->validated('before_id');

        // Ordered by id, a UUIDv7, so by when each was saved; and, as with
        // the history, one row past the limit answers "is there more?".
        $saved = $viewer->savedMessages()
            ->whereHas('message', fn (Builder $message): Builder => $message->saveableBy($viewer))
            ->when($beforeId !== null, fn (Builder $query): Builder => $query->where('id', '<', $beforeId))
            ->with(['message.sender:id,name,avatar_url', 'message.attachments', 'message.conversation'])
            ->latest('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $saved->count() > $limit;
        $saved = $saved->take($limit)->values();

        // A direct conversation goes by the other person's name, so those
        // need their members; a group's title is on the conversation itself.
        Collection::make($saved->pluck('message.conversation')->where('type', 'direct')->unique('id')->values())
            ->load('participants.user:id,name');

        return SavedMessageResource::collection($saved)
            ->additional([
                'meta' => [
                    'has_more' => $hasMore,
                    'next_before_id' => $hasMore ? $saved->last()?->id : null,
                ],
            ]);
    }

    /**
     * The ids of every message the viewer has saved, so a message's menu
     * can offer Save or Remove from saved. Their own rows, unfiltered: an id
     * from a conversation they've left is never asked about.
     */
    public function ids(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->savedMessages()->pluck('message_id')]);
    }

    /**
     * Save a message. Saving one that's already saved changes nothing and
     * keeps its place in the list: 204 either way.
     *
     * 404 for a deleted group, a group event line or a deleted message, and
     * 403 for a conversation the viewer isn't in, or isn't in any more.
     */
    public function store(Request $request, Message $message): Response|JsonResponse
    {
        $viewer = $request->user();

        if ($denied = $this->denyUnlessSaveable($viewer, $message)) {
            return $denied;
        }

        // ON CONFLICT DO NOTHING on (user_id, message_id): two saves racing
        // write one row between them, and neither fails.
        SavedMessage::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'user_id' => $viewer->id,
            'message_id' => $message->id,
            'created_at' => now(),
        ]);

        return response()->noContent();
    }

    /**
     * Take a message off the viewer's saved list: 204 whether or not it was
     * on it. Only their own row is touched, so this is allowed wherever they
     * have a place in the conversation, even once the message is deleted or
     * they've left. Anyone else gets 403, as from the history.
     */
    public function destroy(Request $request, Message $message): Response|JsonResponse
    {
        $viewer = $request->user();

        if (! $message->conversation->participants()->where('user_id', $viewer->id)->exists()) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $viewer->savedMessages()->where('message_id', $message->id)->delete();

        return response()->noContent();
    }

    /**
     * Saving's gate, in the same order and shape as the history endpoints:
     * a deleted group is gone (404), and a conversation the viewer isn't in
     * any more is closed to them (403). Past that, it's Message::saveableBy()
     * that says whether this message can be saved (404 if not).
     */
    private function denyUnlessSaveable(User $viewer, Message $message): ?JsonResponse
    {
        $conversation = $message->conversation;

        if ($conversation->deleted_at !== null) {
            return response()->json(['data' => ['message' => 'Conversation not found']], 404);
        }

        if (! $conversation->participants()->active()->where('user_id', $viewer->id)->exists()) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        if (! Message::query()->saveableBy($viewer)->whereKey($message->id)->exists()) {
            return response()->json(['data' => ['message' => 'Message not found']], 404);
        }

        return null;
    }
}
