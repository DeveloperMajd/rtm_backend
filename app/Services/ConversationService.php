<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    /**
     * Return the existing direct conversation between two users, or create one.
     * `wasRecentlyCreated` on the result tells the caller whether it is new.
     */
    public function findOrCreateDirect(User $a, User $b): Conversation
    {
        $existing = Conversation::query()
            ->where('type', 'direct')
            ->whereHas('participants', fn ($query) => $query->where('user_id', $a->id))
            ->whereHas('participants', fn ($query) => $query->where('user_id', $b->id))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($a, $b): Conversation {
            $conversation = Conversation::create([
                'created_by_user_id' => $a->id,
                'type' => 'direct',
            ]);

            foreach ([$a->id, $b->id] as $userId) {
                $conversation->participants()->create([
                    'user_id' => $userId,
                    'role' => 'participant',
                    'joined_at' => now(),
                ]);
            }

            return $conversation;
        });
    }
}
