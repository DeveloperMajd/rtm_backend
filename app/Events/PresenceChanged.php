<?php

namespace App\Events;

use App\Enums\PresenceStatus;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone came online, went away, came back, or went offline: told to
 * everyone who sees their status (PresenceService::audienceOf), on each
 * one's own channel, so the dot changes at once rather than at the next
 * refresh. One broadcast however many are told: Reverb gets every channel
 * in the one call.
 *
 * Only a change is told, never a heartbeat that keeps things as they were.
 * Offline carries no "last seen": that depends on who's asking (their
 * settings and the viewer's), so the client asks for it.
 */
class PresenceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $recipientIds  everyone who sees their status
     */
    public function __construct(
        public readonly string $userId,
        public readonly PresenceStatus $status,
        public readonly array $recipientIds,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (string $userId): PrivateChannel => new PrivateChannel('App.Models.User.'.$userId),
            $this->recipientIds,
        );
    }

    /**
     * @return array{user_id: string, presence_status: string, is_online: bool}
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'presence_status' => $this->status->value,
            'is_online' => $this->status->isOnline(),
        ];
    }
}
