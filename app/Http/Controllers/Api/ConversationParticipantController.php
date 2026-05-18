<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationParticipantResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationParticipantController extends Controller
{

    public function index(Conversation $conversation): AnonymousResourceCollection|JsonResponse
    {
        $isParticipant = $conversation->participants()
            ->where('user_id', 1) // replace with auth()->id()
            ->exists();

        if (! $isParticipant) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $participants = $conversation->participants()->with('user')->get();

        return ConversationParticipantResource::collection($participants);
    }

    public function destroy(Conversation $conversation, User $user): JsonResponse
    {
        $requestingUserId = 1; // replace with auth()->id()

        $isParticipant = $conversation->participants()
            ->where('user_id', $requestingUserId)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $participant = $conversation->participants()
            ->where('user_id', $user->id)
            ->first();

        if (! $participant) {
            return response()->json(['data' => ['message' => 'User is not a participant']], 404);
        }

        $participant->delete();

        return response()->json(['data' => ['message' => 'Left conversation successfully']], 200);
    }
}
