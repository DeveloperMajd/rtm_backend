<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchMessagesRequest;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Http\Resources\MessageSearchResource;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MessageController extends Controller
{
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $perPage = $request->query('per_page', 10);

        $messages = $conversation->messages()
            ->with(['sender:id,name', 'reactions.user:id,name', 'replyTo.sender:id,name', 'attachments'])
            ->latest()
            ->paginate($perPage);

        $messages->setCollection($messages->getCollection()->reverse()->values());

        return MessageResource::collection($messages);
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

        $message->load(['sender', 'reactions.user:id,name', 'replyTo.sender:id,name', 'attachments']);

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

        $message->load(['sender', 'reactions.user:id,name', 'replyTo.sender:id,name', 'attachments']);

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

        $message->load(['sender', 'reactions.user:id,name', 'replyTo.sender:id,name', 'attachments']);

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
            ->with(['sender:id,name', 'conversation.participants.user'])
            ->orderByRaw("ts_rank(search_vector, {$tsQuery}) DESC", [$query, $query])
            ->limit(20)
            ->get();

        return MessageSearchResource::collection($messages);
    }
}
