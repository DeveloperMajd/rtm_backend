<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToCurrentMembers;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone is typing. Told to each other current member's own channel: their
 * chat list says so on the conversation's row, and their open room above
 * the composer. One broadcast however many are told: Reverb gets every
 * channel in the one call.
 */
class TypingIndicator implements ShouldBroadcast
{
    use BroadcastsToCurrentMembers, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $conversationId,
        public readonly User $user,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return $this->currentMembersOf($this->conversationId, except: $this->user->id);
    }

    /**
     * @return array{conversation_id: string, user_id: string, name: string}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->user->id,
            'name' => $this->user->name,
        ];
    }
}
