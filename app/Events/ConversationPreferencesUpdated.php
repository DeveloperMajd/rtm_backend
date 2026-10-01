<?php

namespace App\Events;

use App\Models\ConversationParticipant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone pinned, muted or archived a conversation (or undid it) — told to
 * their own other tabs and devices, on their private channel, so every
 * open copy of the list agrees. Nobody else hears about it: these are one
 * person's view of a conversation, not part of it.
 *
 * Carries the values as they were, not the model (see ConversationRead).
 */
class ConversationPreferencesUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $userId;

    public readonly string $conversationId;

    public readonly ?string $pinnedAt;

    public readonly ?string $mutedAt;

    public readonly ?string $archivedAt;

    public function __construct(ConversationParticipant $participant)
    {
        $this->userId = $participant->user_id;
        $this->conversationId = $participant->conversation_id;
        $this->pinnedAt = $participant->pinned_at?->toJSON();
        $this->mutedAt = $participant->muted_at?->toJSON();
        $this->archivedAt = $participant->archived_at?->toJSON();
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->userId),
        ];
    }

    /**
     * @return array{conversation_id: string, pinned_at: string|null, muted_at: string|null, archived_at: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'pinned_at' => $this->pinnedAt,
            'muted_at' => $this->mutedAt,
            'archived_at' => $this->archivedAt,
        ];
    }
}
