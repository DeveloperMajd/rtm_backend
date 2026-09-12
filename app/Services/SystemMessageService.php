<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;

class SystemMessageService
{
    /**
     * Record a permanent in-thread group event line ("X added Y", "X left",
     * ...). Stored as a message (type='system', no sender, empty body) so it
     * flows through the same pagination/broadcast/read pipeline as any other
     * message; display copy is resolved from event_type + metadata on the
     * frontend, keeping the strings in one place.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(Conversation $conversation, string $eventType, array $metadata = []): Message
    {
        $message = $conversation->messages()->create([
            'type' => 'system',
            'event_type' => $eventType,
            'metadata' => $metadata,
            'body' => '',
        ]);

        $conversation->last_message_id = $message->id;
        $conversation->last_message_at = now();
        $conversation->save();

        $message->load(['sender', 'reactions.user:id,name,avatar_url', 'replyTo.sender:id,name,avatar_url', 'attachments']);

        broadcast(new MessageSent($message));

        return $message;
    }
}
