<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationParticipantsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly User $target,
    ) {}

    public function broadcastOn(): array
    {
        $participantChannels = $this->conversation
            ->participants()
            ->pluck('user_id')
            ->push($this->target->id)
            ->unique()
            ->map(fn ($userId) => new PrivateChannel('App.Models.User.'.$userId))
            ->all();

        return [
            new PrivateChannel('conversation.'.$this->conversation->id),
            ...$participantChannels,
        ];
    }

    /**
     * A broadcast payload is shared across every recipient, so this can't
     * reuse ConversationResource — its unread_count/other_participant
     * fields are computed against a single per-request user, which doesn't
     * exist (and wouldn't mean the same thing to every listener) here.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->conversation->id,
            'type' => $this->conversation->type,
            'title' => $this->conversation->title,
            'participants' => $this->conversation->participants->map(fn ($participant) => [
                'user_id' => $participant->user_id,
                'name' => $participant->user?->name,
                'role' => $participant->role,
            ]),
        ];
    }
}
