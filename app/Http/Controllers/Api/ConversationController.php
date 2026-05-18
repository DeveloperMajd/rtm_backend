<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $conversations = Conversation::whereHas('participants', function ($query): void {
            $query->where('user_id', 1); // replace with auth()->id() once auth is implemented
        })->with('latestMessage.sender')->get();

        return ConversationResource::collection($conversations);
    }

    public function show(Conversation $conversation): ConversationResource|JsonResponse
    {
        $isParticipant = $conversation->participants()->where('user_id', 1)->exists(); // replace with auth()->id()

        if (! $isParticipant) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $conversation->load('participants.user');

        return new ConversationResource($conversation);
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        $conversation = Conversation::create([
            'created_by_user_id' => 1, // replace with auth()->id()
            'type' => $request->input('type', 'direct'),
            'title' => $request->input('title'),
        ]);


        $conversation->participants()->create([
            'user_id' => 1, // replace with auth()->id()
            'role' => 'admin',
            'joined_at' => now(),
            'conversation_id' => $conversation->id,
        ]);

        foreach ($request->input('participant_ids', []) as $userId) {
            $conversation->participants()->create([
                'user_id' => $userId,
                'role' => 'participant',
                'joined_at' => now(),
                'conversation_id' => $conversation->id,
            ]);
        }

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }
}
