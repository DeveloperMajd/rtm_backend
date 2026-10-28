<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationParticipantsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddConversationParticipantRequest;
use App\Http\Requests\UpdateConversationParticipantRoleRequest;
use App\Http\Resources\ConversationParticipantResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationParticipantService;
use App\Services\LastSeenVisibility;
use App\Services\PresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationParticipantController extends Controller
{
    /**
     * Who's in the group now, with their presence: for its current members
     * (authorized on the route, ConversationPolicy::participate). Someone who
     * left has the group's members as they were then on the conversation
     * itself (ConversationResource).
     */
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $participants = $conversation->participants()->with('user')->get();

        app(LastSeenVisibility::class)->prime($request->user(), $participants->pluck('user_id'));
        app(PresenceService::class)->prime($participants->pluck('user_id'));

        return ConversationParticipantResource::collection($participants);
    }

    public function store(
        AddConversationParticipantRequest $request,
        Conversation $conversation,
        ConversationParticipantService $service,
    ): JsonResponse {
        $targetUser = User::findOrFail($request->input('user_id'));

        $participant = $service->addParticipant($conversation, $request->user(), $targetUser);
        $participant->load('user');

        $conversation->load('participants.user');
        broadcast(new ConversationParticipantsUpdated($conversation, $targetUser));

        return (new ConversationParticipantResource($participant))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * A participant leaves on their own. Anyone may leave a group, but the
     * sole active admin must promote someone else first if other active
     * members remain. Direct conversations keep the old hard-delete
     * behaviour — there is no "left" state to show for a 1:1 chat.
     */
    public function destroy(
        Request $request,
        Conversation $conversation,
        User $user,
        ConversationParticipantService $service,
    ): JsonResponse {
        if ($user->id !== $request->user()->id) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        $participant = $conversation->participants()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        if (! $participant) {
            return response()->json(['data' => ['message' => 'User is not a participant']], 404);
        }

        if ($conversation->type === 'group') {
            if ($participant->role === 'admin' && $this->wouldLeaveGroupWithoutAdmin($conversation, $user)) {
                return response()->json([
                    'data' => ['message' => 'Promote another member to admin before you can leave.'],
                ], 422);
            }

            $service->leave($conversation, $request->user());
        } else {
            $participant->delete();
        }

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

        $service->kickParticipant($conversation, $request->user(), $user);

        $conversation->load('participants.user');
        broadcast(new ConversationParticipantsUpdated($conversation, $user));

        return response()->json(['data' => ['message' => 'Participant removed successfully']], 200);
    }

    public function update(
        UpdateConversationParticipantRoleRequest $request,
        Conversation $conversation,
        User $user,
        ConversationParticipantService $service,
    ): JsonResponse {
        $participant = $service->updateRole($conversation, $request->user(), $user, $request->input('role'));
        $participant->load('user');

        $conversation->load('participants.user');
        broadcast(new ConversationParticipantsUpdated($conversation, $user));

        return (new ConversationParticipantResource($participant))->response();
    }

    /**
     * Would removing $leaver's admin status leave the group with no admin
     * while other active members remain? (A conversation with no other
     * active members may always be left — there is no one left to manage.)
     */
    private function wouldLeaveGroupWithoutAdmin(Conversation $conversation, User $leaver): bool
    {
        $otherActiveCount = $conversation->participants()
            ->whereNull('left_at')
            ->where('user_id', '!=', $leaver->id)
            ->count();

        if ($otherActiveCount === 0) {
            return false;
        }

        $activeAdminCount = $conversation->participants()
            ->where('role', 'admin')
            ->whereNull('left_at')
            ->count();

        return $activeAdminCount <= 1;
    }
}
