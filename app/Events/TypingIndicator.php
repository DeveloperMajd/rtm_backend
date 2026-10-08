<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone is typing. Told to the conversation (its open room shows it above
 * the composer) and to each other member's own channel, so their chat list
 * can say so on the conversation's row whether it's open or not. One
 * broadcast whichever way: Reverb gets every channel in the one call.
 */
class TypingIndicator implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $recipientIds  the conversation's other current members
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly User $user,
        public readonly array $recipientIds = [],
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->conversationId),
            ...array_map(fn (string $userId): PrivateChannel => new PrivateChannel('App.Models.User.'.$userId), $this->recipientIds),
        ];
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
