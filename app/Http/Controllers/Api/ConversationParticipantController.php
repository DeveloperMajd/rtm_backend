<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationParticipantsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddConversationParticipantRequest;
use App\Http\Resources\ConversationParticipantResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationParticipantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationParticipantController extends Controller
{
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection|JsonResponse
    {
        $isParticipant = $conversation->participants()
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $participants = $conversation->participants()->with('user')->get();

        return ConversationParticipantResource::collection($participants);
    }

    public function store(
        AddConversationParticipantRequest $request,
        Conversation $conversation,
        ConversationParticipantService $service,
    ): JsonResponse {
        $targetUser = User::findOrFail($request->input('user_id'));

        $participant = $service->addParticipant($conversation, $targetUser);
        $participant->load('user');

        $conversation->load('participants.user');
        broadcast(new ConversationParticipantsUpdated($conversation, $targetUser));

        return (new ConversationParticipantResource($participant))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        if ($user->id !== $request->user()->id) {
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

    public function kick(
        Request $request,
        Conversation $conversation,
        User $user,
        ConversationParticipantService $service,
    ): JsonResponse {
        if (! $request->user()->can('kickParticipant', [$conversation, $user])) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $service->kickParticipant($conversation, $user);

        $conversation->load('participants.user');
        broadcast(new ConversationParticipantsUpdated($conversation, $user));

        return response()->json(['data' => ['message' => 'Participant removed successfully']], 200);
    }
}
