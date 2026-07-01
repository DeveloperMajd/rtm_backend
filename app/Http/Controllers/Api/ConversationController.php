<?php

namespace App\Http\Controllers\Api;

use App\Events\TypingIndicator;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = Conversation::whereHas('participants', function ($query) use ($request): void {
            $query->where('user_id', $request->user()->id);
        })->with(['latestMessage.sender', 'participants.user'])->get();

        return ConversationResource::collection($conversations);
    }

    public function show(Request $request, Conversation $conversation): ConversationResource|JsonResponse
    {
        $isParticipant = $conversation->participants()->where('user_id', $request->user()->id)->exists();

        if (! $isParticipant) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $conversation->load('participants.user');

        return new ConversationResource($conversation);
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        $type = $request->input('type', 'direct');

        if ($type === 'direct') {
            $otherUserId = $request->input('participant_ids')[0];

            $existing = Conversation::where('type', 'direct')
                ->whereHas('participants', fn ($query) => $query->where('user_id', $request->user()->id))
                ->whereHas('participants', fn ($query) => $query->where('user_id', $otherUserId))
                ->first();

            if ($existing) {
                $existing->load('participants.user');

                return (new ConversationResource($existing))->response();
            }
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

        $conversation->load('participants.user');

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
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
}
