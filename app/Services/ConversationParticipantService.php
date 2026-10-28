<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

class ConversationParticipantService
{
    public function __construct(private readonly SystemMessageService $systemMessages) {}

    /**
     * Add (or re-activate a previously left/kicked) participant. Idempotent
     * for someone who is already an active participant.
     */
    public function addParticipant(Conversation $conversation, User $actor, User $target): ConversationParticipant
    {
        $existing = $conversation->participants()->where('user_id', $target->id)->first();

        if ($existing && $existing->left_at === null) {
            return $existing;
        }

        if ($existing) {
            // The cutoff goes with left_at. Left in place, it kept a re-added
            // member's history and list preview frozen at the moment they
            // were removed — back in the group, but seeing none of it. They
            // see the whole history again, including what was said while
            // they were out: one cutoff can't describe a gap in the middle.
            $existing->update([
                'left_at' => null,
                'left_at_message_id' => null,
                'left_snapshot' => null,
                'role' => 'participant',
                'joined_at' => now(),
            ]);
            $participant = $existing;
        } else {
            $participant = $conversation->participants()->create([
                'user_id' => $target->id,
                'role' => 'participant',
                'joined_at' => now(),
            ]);
        }

        $this->systemMessages->record($conversation, 'member_added', [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'target_id' => $target->id,
            'target_name' => $target->name,
        ]);

        return $participant;
    }

    /**
     * Remove a participant. Their row is kept (left_at set) so the group
     * stays on their list, read-only, rather than disappearing.
     *
     * The cutoff is pinned to the system message's own id (UUIDv7, precisely
     * ordered) rather than its second-precision `created_at` — otherwise a
     * message created in the same second as this one could ambiguously fall
     * on either side of the "no new messages will be seen" boundary.
     */
    public function kickParticipant(Conversation $conversation, User $actor, User $target): void
    {
        $message = $this->systemMessages->record($conversation, 'member_removed', [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'target_id' => $target->id,
            'target_name' => $target->name,
        ]);

        $conversation->participants()->where('user_id', $target->id)->update([
            'left_at' => $message->created_at,
            'left_at_message_id' => $message->id,
            'left_snapshot' => $this->snapshotFor($conversation, $target),
        ]);
    }

    /**
     * A participant leaves on their own. Same "keep the row, pin the cutoff
     * to the system message" treatment as a kick; authorization (the
     * sole-admin guard) is enforced by the caller.
     */
    public function leave(Conversation $conversation, User $actor): void
    {
        $message = $this->systemMessages->record($conversation, 'member_left', [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
        ]);

        $conversation->participants()->where('user_id', $actor->id)->update([
            'left_at' => $message->created_at,
            'left_at_message_id' => $message->id,
            'left_snapshot' => $this->snapshotFor($conversation, $actor),
        ]);
    }

    public function updateRole(Conversation $conversation, User $actor, User $target, string $role): ConversationParticipant
    {
        $participant = $conversation->participants()->where('user_id', $target->id)->firstOrFail();
        $participant->update(['role' => $role]);

        $this->systemMessages->record(
            $conversation,
            $role === 'admin' ? 'member_promoted' : 'member_demoted',
            [
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'target_id' => $target->id,
                'target_name' => $target->name,
            ],
        );

        return $participant;
    }

    /**
     * The group as someone leaving it last sees it, kept on their row: its
     * title and everyone else still in it, with their roles. What they're
     * shown of the group from then on (ConversationResource) comes from
     * here, so nothing that happens after they've gone reaches them: not who
     * joins or leaves, not a new admin, not a new name.
     *
     * Encoded here rather than by the model's cast: it's written with a
     * query-builder update, which doesn't cast.
     */
    private function snapshotFor(Conversation $conversation, User $leaving): string
    {
        $members = $conversation->participants()
            ->whereNull('left_at')
            ->where('user_id', '!=', $leaving->id)
            ->orderBy('id')
            ->get(['user_id', 'role']);

        return json_encode([
            'title' => $conversation->title,
            'members' => $members->map(fn (ConversationParticipant $member): array => [
                'user_id' => $member->user_id,
                'role' => $member->role,
            ])->all(),
        ], JSON_THROW_ON_ERROR);
    }

    public function rename(Conversation $conversation, User $actor, string $newTitle): Conversation
    {
        $oldTitle = $conversation->title;
        $conversation->update(['title' => $newTitle]);

        $this->systemMessages->record($conversation, 'group_renamed', [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'old_title' => $oldTitle,
            'new_title' => $newTitle,
        ]);

        return $conversation;
    }
}
