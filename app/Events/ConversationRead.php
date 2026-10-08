<?php

namespace App\Events;

use App\Models\ConversationParticipant;
use App\Services\ReadReceiptVisibility;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone's read pointer moved forward — how "Sent" becomes "Seen" on the
 * other people's screens without their asking again.
 *
 * Carries the pointer's values rather than the participant model: the event
 * is queued, and by the time it's broadcast the row may have moved on
 * again. Each broadcast then says exactly what was true when it was sent,
 * and a client keeps whichever pointer is furthest along.
 *
 * It's only sent for someone who shares their read state, and it carries
 * their stretches with it: which of the messages up to the pointer they
 * read while sharing (see ReadReceiptVisibility). A client can't work that
 * out from the pointer alone once they've switched read receipts off and on.
 */
class ConversationRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $conversationId;

    public readonly string $userId;

    public readonly string $lastReadMessageId;

    public readonly ?string $lastReadAt;

    /** @var list<array{0: string|null, 1: string|null}> */
    public readonly array $stretches;

    public function __construct(ConversationParticipant $participant)
    {
        $this->conversationId = $participant->conversation_id;
        $this->userId = $participant->user_id;
        $this->lastReadMessageId = $participant->last_read_message_id;
        $this->lastReadAt = $participant->last_read_at?->toJSON();
        $this->stretches = ReadReceiptVisibility::sharedReads($participant, true)['stretches'];
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->conversationId),
        ];
    }

    /**
     * @return array{user_id: string, last_read_message_id: string, last_read_at: string|null, stretches: list<array{0: string|null, 1: string|null}>}
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'last_read_message_id' => $this->lastReadMessageId,
            'last_read_at' => $this->lastReadAt,
            'stretches' => $this->stretches,
        ];
    }
}
