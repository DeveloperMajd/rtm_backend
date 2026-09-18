<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Conversation $conversation) {}

    public function broadcastOn(): array
    {
        $participantChannels = $this->conversation
            ->participants()
            ->pluck('user_id')
            ->map(fn ($userId) => new PrivateChannel('App.Models.User.'.$userId))
            ->all();

        return [
            new PrivateChannel('conversation.'.$this->conversation->id),
            ...$participantChannels,
        ];
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->conversation->id];
    }
}
