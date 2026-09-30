<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\MessageContextRequest;
use App\Http\Requests\MessageHistoryRequest;
use App\Http\Requests\SearchMessagesRequest;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\MessageSearchResource;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MessageController extends Controller
{
    public function index(MessageHistoryRequest $request, Conversation $conversation): AnonymousResourceCollection|JsonResponse
    {
        if ($denied = $this->denyUnlessReadable($request, $conversation)) {
            return $denied;
        }

        // Keyset ("cursor") pagination, not offset. A conversation grows at
        // the newest end while it is being read, and `?page=N` is an offset
        // from that end — so every message that arrives shifts every page
        // boundary down by one, and the next page a client asks for overlaps
        // the one it already holds, handing back rows twice. Anchoring on
        // "older than this id" instead makes a page boundary mean the same
        // thing regardless of what has arrived since.
        $limit = max(1, min((int) $request->query('limit', 25), 100));
        $beforeId = $request->validated('before_id');
        $afterId = $request->validated('after_id');

        // A member who has left (or been removed) sees history frozen at the
        // moment they left — no new messages, per the read-only group rule.
        $query = $this->readableMessages($request, $conversation);

        // Reading forwards from a message, towards the newest: how a client
        // that opened somewhere in the middle of the history (a jump to a
        // reply's original, or a search result) catches back up. Same
        // cursor rule in the other direction; `has_more` then means "more
        // newer messages", and the cursor to continue from is next_after_id.
        if ($afterId !== null) {
            $messages = $query->where('id', '>', $afterId)->oldest('id')->limit($limit + 1)->get();

            $hasMore = $messages->count() > $limit;
            $messages = $messages->take($limit);

            return MessageResource::collection($messages->values())
                ->additional([
                    'meta' => [
                        'has_more' => $hasMore,
                        'next_after_id' => $hasMore ? $messages->last()?->id : null,
                    ],
                ]);
        }

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        // Order by id, not created_at: ids are UUIDv7 (precisely, monotonically
        // time-ordered); the timestamp column is only second-precision, which
        // is ambiguous whenever two messages land in the same second. That
        // same property is what makes the id usable as the cursor.
        //
        // One row beyond the limit is fetched purely to answer "is there more
        // history?" without a second COUNT(*) over the conversation.
        $messages = $query->latest('id')->limit($limit + 1)->get();

        $hasMore = $messages->count() > $limit;
        $messages = $messages->take($limit);

        // Newest-first while paginating (so the cursor walks backwards through
        // history), oldest-first in the response (so it renders top to bottom).
        $oldestInBatch = $messages->last();

        return MessageResource::collection($messages->reverse()->values())
            ->additional([
                'meta' => [
                    'has_more' => $hasMore,
                    'next_before_id' => $hasMore ? $oldestInBatch?->id : null,
                ],
            ]);
    }

    /**
     * A window of history around one message — what a client needs to jump
     * to a message that isn't loaded yet (a reply's original, a search
     * result, a link). Returns up to `before` messages older than it, the
     * message itself and up to `after` newer, oldest first, with a cursor
     * for each direction so the client can keep reading either way through
     * the ordinary history endpoint.
     *
     * 403 for someone who isn't in the conversation; 404 when the message
     * isn't in it, falls after the viewer left, or the group was deleted. A
     * deleted message is still returned, as the same "deleted" placeholder
     * the history shows, so a jump to one lands somewhere.
     */
    public function context(MessageContextRequest $request, Conversation $conversation, Message $message): AnonymousResourceCollection|JsonResponse
    {
        if ($denied = $this->denyUnlessReadable($request, $conversation)) {
            return $denied;
        }

        $readable = $this->readableMessages($request, $conversation);

        if ($message->conversation_id !== $conversation->id || ! (clone $readable)->whereKey($message->id)->exists()) {
            return response()->json(['data' => ['message' => 'Message not found']], 404);
        }

        $beforeCount = max(0, min((int) $request->validated('before', 20), 50));
        $afterCount = max(0, min((int) $request->validated('after', 20), 50));

        // One row beyond each count answers "is there more that way?", as in
        // index(). The target itself comes from the same query, so it carries
        // the same relations as its neighbours.
        $older = (clone $readable)->where('id', '<', $message->id)->latest('id')->limit($beforeCount + 1)->get();
        $newer = (clone $readable)->where('id', '>', $message->id)->oldest('id')->limit($afterCount + 1)->get();
        $target = (clone $readable)->whereKey($message->id)->get();

        $hasMoreBefore = $older->count() > $beforeCount;
        $hasMoreAfter = $newer->count() > $afterCount;
        $older = $older->take($beforeCount)->reverse();
        $newer = $newer->take($afterCount);

        $window = $older->concat($target)->concat($newer)->values();

        return MessageResource::collection($window)
            ->additional([
                'meta' => [
                    'target_id' => $message->id,
                    'has_more_before' => $hasMoreBefore,
                    'has_more_after' => $hasMoreAfter,
                    'next_before_id' => $hasMoreBefore ? $window->first()->id : null,
                    'next_after_id' => $hasMoreAfter ? $window->last()->id : null,
                ],
            ]);
    }

    public function store(StoreMessageRequest $request): JsonResponse
    {
        $conversation = Conversation::find($request->input('conversation_id'));

        $message = $conversation->messages()->create([
            'sender_user_id' => $request->user()->id,
            'body' => $request->input('body') ?? '',
            'reply_to_message_id' => $request->input('reply_to_message_id'),
        ]);

        $attachmentIds = $request->input('attachment_ids', []);

        if ($attachmentIds !== []) {
            // Claim the caller's own still-unlinked uploads (StoreMessageRequest
            // has already validated ownership); the same guards here keep the
            // update race-safe.
            $linked = Attachment::query()
                ->whereIn('id', $attachmentIds)
                ->where('uploaded_by_user_id', $request->user()->id)
                ->whereNull('message_id')
                ->update(['message_id' => $message->id]);

            $message->attachments_count = $linked;
            $message->save();
        }

        $conversation->last_message_at = now();
        $conversation->last_message_id = $message->id;
        $conversation->save();

        $message->load(['sender', 'reactions.user:id,name,avatar_url', 'replyTo.sender:id,name,avatar_url', 'attachments']);

        broadcast(new MessageSent($message));

        return (new MessageResource($message))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateMessageRequest $request, Message $message): MessageResource
    {
        $message->body = $request->input('body');
        $message->edited_at = now();
        $message->save();

        $message->load(['sender', 'reactions.user:id,name,avatar_url', 'replyTo.sender:id,name,avatar_url', 'attachments']);

        broadcast(new MessageUpdated($message));

        return new MessageResource($message);
    }

    public function destroy(Request $request, Message $message): Response
    {
        if ($message->sender_user_id !== $request->user()->id) {
            return response()->noContent(403);
        }

        if ($message->deleted_at !== null) {
            return response()->noContent();
        }

        $message->body = '';
        $message->deleted_at = now();
        $message->save();

        $message->load(['sender', 'reactions.user:id,name,avatar_url', 'replyTo.sender:id,name,avatar_url', 'attachments']);

        broadcast(new MessageUpdated($message));

        return response()->noContent();
    }

    public function search(SearchMessagesRequest $request): AnonymousResourceCollection
    {
        $query = trim((string) $request->input('q'));

        // search_vector combines a literal representation (weight A) and a
        // stemmed one (weight B) of the message body. Matching against both
        // in one query means a plain word search still finds inflected forms
        // ("run" finds "running"), while ts_rank's weighting means a literal
        // match always outranks one that only exists because of English
        // over-stemming (e.g. "universe"/"university" both stem to 'univers').
        $tsQuery = "(websearch_to_tsquery('simple', ?) || websearch_to_tsquery('english', ?))";

        $messages = Message::query()
            ->whereHas('conversation.participants', fn ($q) => $q->where('user_id', $request->user()->id))
            ->whereRaw("search_vector @@ {$tsQuery}", [$query, $query])
            ->with(['sender:id,name,avatar_url', 'conversation.participants.user'])
            ->orderByRaw("ts_rank(search_vector, {$tsQuery}) DESC", [$query, $query])
            ->limit(20)
            ->get();

        return MessageSearchResource::collection($messages);
    }

    /**
     * The history endpoints' shared gate, in the same order and shape as
     * ConversationController::show(): a deleted group is gone for everyone
     * (404), and someone who was never in the conversation gets 403. A
     * member who left passes — they keep read access to the history up to
     * the moment they left, which readableMessages() enforces.
     */
    private function denyUnlessReadable(Request $request, Conversation $conversation): ?JsonResponse
    {
        if ($conversation->deleted_at !== null) {
            return response()->json(['data' => ['message' => 'Conversation not found']], 404);
        }

        $isParticipant = $conversation->participants()->where('user_id', $request->user()->id)->exists();

        if (! $isParticipant) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        return null;
    }

    /**
     * @return HasMany<Message, Conversation>
     */
    private function readableMessages(Request $request, Conversation $conversation): HasMany
    {
        return $conversation->messages()
            ->visibleTo($request->user())
            ->with(['sender:id,name,avatar_url', 'reactions.user:id,name,avatar_url', 'replyTo.sender:id,name,avatar_url', 'attachments']);
    }
}
