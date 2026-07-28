<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

class ConversationParticipantService
{
    public function addParticipant(Conversation $conversation, User $user): ConversationParticipant
    {
        return $conversation->participants()->firstOrCreate(
            ['user_id' => $user->id],
            ['role' => 'participant', 'joined_at' => now()],
        );
    }

    public function kickParticipant(Conversation $conversation, User $user): void
    {
        $conversation->participants()->where('user_id', $user->id)->delete();
    }
}
