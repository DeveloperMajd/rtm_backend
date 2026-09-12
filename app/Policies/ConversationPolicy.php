<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Determine whether the user can add a participant to the conversation.
     */
    public function addParticipant(User $user, Conversation $conversation): bool
    {
        if ($conversation->type !== 'group') {
            return false;
        }

        return $this->isActiveAdmin($user, $conversation);
    }

    /**
     * Determine whether the user can kick the target participant from the
     * conversation. Blocked if it would leave the group with zero admins.
     */
    public function kickParticipant(User $user, Conversation $conversation, User $target): bool
    {
        if ($user->id === $target->id) {
            return false;
        }

        if (! $this->addParticipant($user, $conversation)) {
            return false;
        }

        $targetParticipant = $this->activeParticipant($conversation, $target);

        if (! $targetParticipant) {
            return false;
        }

        return ! ($targetParticipant->role === 'admin' && $this->activeAdminCount($conversation) <= 1);
    }

    /**
     * Determine whether the user can change the target's role. Blocked if
     * demoting the target would leave the group with zero admins.
     */
    public function updateParticipantRole(User $user, Conversation $conversation, User $target, string $role): bool
    {
        if (! $this->addParticipant($user, $conversation)) {
            return false;
        }

        $targetParticipant = $this->activeParticipant($conversation, $target);

        if (! $targetParticipant) {
            return false;
        }

        if ($role === 'participant' && $targetParticipant->role === 'admin' && $this->activeAdminCount($conversation) <= 1) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can rename the group.
     */
    public function rename(User $user, Conversation $conversation): bool
    {
        return $this->addParticipant($user, $conversation);
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
