<?php

namespace App\Events\Concerns;

use App\Models\ConversationParticipant;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Builder;

/**
 * How what happens in a conversation reaches its members: on each current
 * member's own channel, worked out when the event goes out (on the queue
 * worker), not when it was raised.
 *
 * A conversation has no channel of its own. Reverb checks who may listen to
 * a channel once, when a socket subscribes, and never again: a member
 * removed from a group kept their socket on the group's channel and went on
 * receiving its messages, edits, reactions, read receipts and typing until
 * they disconnected. Asked afresh for every event, whoever has left or been
 * removed isn't told the next thing; nor is anyone once the group is deleted.
 */
trait BroadcastsToCurrentMembers
{
    /**
     * @return array<int, PrivateChannel>
     */
    protected function currentMembersOf(string $conversationId, ?string $except = null): array
    {
        return ConversationParticipant::query()
            ->active()
            ->where('conversation_id', $conversationId)
            ->whereHas('conversation', fn (Builder $conversation): Builder => $conversation->visible())
            ->when($except !== null, fn (Builder $query): Builder => $query->where('user_id', '!=', $except))
            ->pluck('user_id')
            ->map(fn (string $userId): PrivateChannel => new PrivateChannel('App.Models.User.'.$userId))
            ->all();
    }
}
