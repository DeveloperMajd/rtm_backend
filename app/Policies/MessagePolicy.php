<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * What someone may do to a message once it's been sent. Everything here
 * takes part in the conversation, so it starts from
 * ConversationPolicy::participate(): an active member of a conversation
 * that hasn't been deleted. A member who left or was removed still reads
 * the history up to then, but changes nothing in it.
 */
class MessagePolicy
{
    /**
     * Add or take back a reaction: on someone's message, not a group event
     * line, and not one that's been deleted.
     */
    public function react(User $user, Message $message): Response
    {
        $participation = $this->participation($user, $message);

        if ($participation->denied()) {
            return $participation;
        }

        if ($message->type !== 'user' || $message->deleted_at !== null) {
            return Response::deny('This message can’t be reacted to.');
        }

        return Response::allow();
    }

    /**
     * Edit the text of one's own message. (A deleted one is turned away by
     * UpdateMessageRequest, as invalid rather than forbidden.)
     */
    public function update(User $user, Message $message): Response
    {
        return $this->ownMessage($user, $message);
    }

    /**
     * Delete one's own message.
     */
    public function delete(User $user, Message $message): Response
    {
        return $this->ownMessage($user, $message);
    }

    private function ownMessage(User $user, Message $message): Response
    {
        $participation = $this->participation($user, $message);

        if ($participation->denied()) {
            return $participation;
        }

        return $message->sender_user_id === $user->id
            ? Response::allow()
            : Response::deny('Only the sender can change a message.');
    }

    private function participation(User $user, Message $message): Response
    {
        return Gate::forUser($user)->inspect('participate', $message->conversation);
    }
}
