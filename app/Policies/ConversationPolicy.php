<?php

namespace App\Policies;

use App\Models\Conversation;
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

        return $conversation->participants()
            ->where('user_id', $user->id)
            ->where('role', 'admin')
            ->exists();
    }

    /**
     * Determine whether the user can kick the target participant from the conversation.
     */
    public function kickParticipant(User $user, Conversation $conversation, User $target): bool
    {
        if ($user->id === $target->id) {
            return false;
        }

        return $this->addParticipant($user, $conversation);
    }
}
