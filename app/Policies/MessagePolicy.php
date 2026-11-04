<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * What someone may do with a message once it's been sent. Reading it starts
 * from ConversationPolicy::view(), anything else from participate(): an
 * active member of a conversation that hasn't been deleted. A member who
 * left or was removed still reads the history up to then, but changes
 * nothing in it.
 */
class MessagePolicy
{
    /**
     * See it, and what's attached to it, wherever the viewer can see it in
     * the history (Message::visibleTo): for a member who left, only up to
     * when they did (404 past that).
     */
    public function view(User $user, Message $message): Response
    {
        $access = Gate::forUser($user)->inspect('view', $message->conversation);

        if ($access->denied()) {
            return $access;
        }

        return Message::query()->visibleTo($user)->whereKey($message->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound('Message not found.');
    }

    /**
     * Put it on the viewer's Saved list: someone's message, still there, in
     * a conversation the viewer is still in (Message::saveableBy, 404 if
     * not).
     */
    public function save(User $user, Message $message): Response
    {
        $participation = $this->participation($user, $message);

        if ($participation->denied()) {
            return $participation;
        }

        return Message::query()->saveableBy($user)->whereKey($message->id)->exists()
            ? Response::allow()
            : Response::denyAsNotFound('Message not found.');
    }

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
