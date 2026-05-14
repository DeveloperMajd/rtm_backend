<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageController extends Controller
{
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $perPage = $request->query('per_page', 10);

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->latest()
            ->paginate($perPage);

        $messages->setCollection($messages->getCollection()->reverse()->values());

        return MessageResource::collection($messages);
    }

    public function store(StoreMessageRequest $request): JsonResponse
    {
        $conversation = Conversation::find($request->input('conversation_id'));

        $message = $conversation->messages()->create([
            'sender_user_id' => $request->input('sender_user_id', 1), // replace with auth()->id()
            'body' => $request->input('body'),
        ]);

        $conversation->last_message_at = now();
        $conversation->save();

        return (new MessageResource($message->load('sender')))
            ->response()
            ->setStatusCode(201);
    }
}
