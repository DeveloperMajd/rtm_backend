<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what in a conversation. Three rules, each the only copy of
 * itself, applied on the routes (`->can()`):
 *
 * - view: anyone with a place in it, members who left included — the
 *   history up to when they left (Message::visibleTo), the group as it was
 *   then (left_snapshot), their own pin, mute and archive;
 * - participate: its current members — writing, typing, reading, reacting,
 *   who's in it now;
 * - manage: a group's current admins — renaming it, adding and removing
 *   people, changing roles, deleting it.
 *
 * A deleted group is gone for everyone (404): a route binding it finds
 * nothing (Conversation is soft-deleted), and a route reaching it through a
 * message (Message::conversation()) is refused here. Anyone else who isn't
 * allowed is refused (403).
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): Response
    {
        return $this->gone($conversation)
            ?? ($conversation->participants()->where('user_id', $user->id)->exists()
                ? Response::allow()
                : Response::deny('You’re not in this conversation.'));
    }

    public function participate(User $user, Conversation $conversation): Response
    {
        return $this->gone($conversation)
            ?? ($this->activeParticipant($conversation, $user)
                ? Response::allow()
                : Response::deny('You’re not a member of this conversation.'));
    }

    public function manage(User $user, Conversation $conversation): Response
    {
        return $this->gone($conversation)
            ?? ($conversation->type === 'group' && $this->isActiveAdmin($user, $conversation)
                ? Response::allow()
                : Response::deny('Only the group’s admins can do that.'));
    }

    public function addParticipant(User $user, Conversation $conversation): Response
    {
        return $this->manage($user, $conversation);
    }

    /**
     * Remove someone else who's still in the group, as long as it keeps an
     * admin.
     */
    public function kickParticipant(User $user, Conversation $conversation, User $target): Response
    {
        $manage = $this->manage($user, $conversation);

        if ($manage->denied()) {
            return $manage;
        }

        if ($user->id === $target->id) {
            return Response::deny('Leave the group instead.');
        }

        $targetParticipant = $this->activeParticipant($conversation, $target);

        if (! $targetParticipant) {
            return Response::deny('They’re not in the group.');
        }

        return $targetParticipant->role === 'admin' && $this->activeAdminCount($conversation) <= 1
            ? Response::deny('The group needs an admin.')
            : Response::allow();
    }

    /**
     * Change the role of someone still in the group, as long as it keeps an
     * admin.
     */
    public function updateParticipantRole(User $user, Conversation $conversation, User $target, string $role): Response
    {
        $manage = $this->manage($user, $conversation);

        if ($manage->denied()) {
            return $manage;
        }

        $targetParticipant = $this->activeParticipant($conversation, $target);

        if (! $targetParticipant) {
            return Response::deny('They’re not in the group.');
        }

        return $role === 'participant' && $targetParticipant->role === 'admin' && $this->activeAdminCount($conversation) <= 1
            ? Response::deny('The group needs an admin.')
            : Response::allow();
    }

    public function rename(User $user, Conversation $conversation): Response
    {
        return $this->manage($user, $conversation);
    }

    /**
     * Groups only — deleting a direct conversation isn't supported (a
     * per-viewer "hide" was tried and reverted; it broke re-opening a
     * conversation from the contacts list, since that flow depends on the
     * conversation staying in the cached list it was just filtered out of).
     */
    public function delete(User $user, Conversation $conversation): Response
    {
        return $this->manage($user, $conversation);
    }

    private function gone(Conversation $conversation): ?Response
    {
        return $conversation->trashed() ? Response::denyAsNotFound('Conversation not found.') : null;
    }

    private function isActiveAdmin(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->whereNull('left_at')
            ->exists();
    }

    private function activeParticipant(Conversation $conversation, User $user): ?ConversationParticipant
    {
        return $conversation->participants()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();
    }

    private function activeAdminCount(Conversation $conversation): int
    {
        return $conversation->participants()
            ->where('role', 'admin')
            ->whereNull('left_at')
            ->count();
    }
}
