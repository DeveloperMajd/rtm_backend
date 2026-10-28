<?php

namespace App\Http\Resources;

use App\Enums\PresenceStatus;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\LastSeenVisibility;
use App\Services\PresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewerParticipant = $this->relationLoaded('participants')
            ? $this->participants->firstWhere('user_id', $request->user()->id)
            : null;
        $viewerLeftAt = $viewerParticipant?->left_at;
        // A group the viewer has left is shown as it was when they left
        // (left_snapshot): who was in it, its title, and nothing live.
        $hasLeft = $viewerLeftAt !== null;

        // Presence is only for people still in it, and only to a viewer
        // still in it.
        $statuses = $this->relationLoaded('participants') && ! $hasLeft
            ? app(PresenceService::class)->statusesOf($this->participants->whereNull('left_at')->pluck('user_id')->all())
            : [];
        $statusOf = fn (string $userId): PresenceStatus => $statuses[$userId] ?? PresenceStatus::Offline;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $viewerParticipant !== null ? $viewerParticipant->titleOf($this->resource) : $this->title,
            'created_by_user_id' => $this->created_by_user_id,
            // A group you've left stops advancing for you — everything below
            // is capped at the moment you left, so no *new* activity leaks in.
            'last_message_at' => $viewerLeftAt && $this->last_message_at
                ? min($this->last_message_at, $viewerLeftAt)
                : $this->last_message_at,
            'viewer_left_at' => $viewerLeftAt,
            'other_participant' => $this->when(
                $this->type === 'direct' && $this->relationLoaded('participants'),
                function () use ($request, $statusOf) {
                    $other = $this->participants->first(fn ($p) => $p->user_id !== $request->user()->id);

                    return $other ? [
                        'id' => $other->user_id,
                        'name' => $other->user?->name,
                        'avatar_url' => $other->user?->avatar_url,
                        // What they've written about themselves, for anyone
                        // they share a conversation with, as their name is.
                        'bio' => $other->user?->bio,
                        'is_online' => $statusOf($other->user_id)->isOnline(),
                        'presence_status' => $statusOf($other->user_id),
                        'last_seen_at' => app(LastSeenVisibility::class)->lastSeenFor($request->user(), $other->user),
                    ] : null;
                },
            ),
            'latest_message' => $this->when(
                $this->relationLoaded('participants'),
                fn () => $this->resolveLatestMessage($viewerParticipant?->left_at_message_id),
            ),
            'participants' => $this->whenLoaded('participants', function () use ($hasLeft, $viewerParticipant, $statusOf, $request) {
                if ($hasLeft) {
                    return $this->participantsAsLeft($viewerParticipant);
                }

                return $this->participants->map(fn (ConversationParticipant $p): array => $this->participantEntry(
                    $p,
                    $p->role,
                    $p->left_at,
                    $p->left_at === null ? $statusOf($p->user_id) : null,
                    $request->user(),
                ))->all();
            }),
            'participants_count' => $this->whenLoaded(
                'participants',
                fn () => $hasLeft
                    ? count($viewerParticipant->left_snapshot['members'] ?? [])
                    : $this->participants->whereNull('left_at')->count(),
            ),
            'unread_count' => $this->when(
                $this->relationLoaded('participants'),
                function () use ($viewerParticipant, $viewerLeftAt) {
                    if (! $viewerParticipant || $viewerLeftAt !== null) {
                        return 0;
                    }

                    // The chat list counts them in its own query
                    // (ConversationController::index); one conversation is
                    // counted here.
                    if (array_key_exists('unread_count', $this->resource->getAttributes())) {
                        return (int) $this->resource->getAttributes()['unread_count'];
                    }

                    return $this->messages()
                        ->userMessages()
                        ->where('sender_user_id', '!=', $viewerParticipant->user_id)
                        ->when(
                            $viewerParticipant->last_read_message_id,
                            fn ($query) => $query->where('id', '>', $viewerParticipant->last_read_message_id),
                        )
                        ->count();
                },
            ),
            // The exact message the viewer had read up to, which is what
            // unread_count above is derived from. Exposing it lets a client
            // put its "new messages" divider on the very message the server
            // counted from, instead of inferring the position by counting
            // unread_count messages backwards from the end and having to
            // reproduce the same rules about whose messages count.
            'last_read_message_id' => $this->when(
                $this->relationLoaded('participants'),
                fn () => $viewerLeftAt !== null ? null : $viewerParticipant?->last_read_message_id,
            ),
            // The viewer's own pin, mute and archive (null = off).
            'pinned_at' => $this->when($this->relationLoaded('participants'), fn () => $viewerParticipant?->pinned_at),
            'muted_at' => $this->when($this->relationLoaded('participants'), fn () => $viewerParticipant?->muted_at),
            'archived_at' => $this->when($this->relationLoaded('participants'), fn () => $viewerParticipant?->archived_at),
            'created_at' => $this->created_at,
            // It moves with every message: for a group the viewer has left,
            // it stops when they did, like last_message_at.
            'updated_at' => $hasLeft ? min($this->updated_at, $viewerLeftAt) : $this->updated_at,
        ];
    }

    /**
     * The members as a viewer who left last saw them (left_snapshot): who
     * was in the group then, with the role each had, and the viewer, as
     * having left. Nobody who joined since, and nothing live: no presence,
     * no "last seen".
     *
     * @return list<array<string, mixed>>
     */
    private function participantsAsLeft(ConversationParticipant $viewer): array
    {
        $rows = $this->participants->keyBy('user_id');

        return collect($viewer->left_snapshot['members'] ?? [])
            ->filter(fn (array $member): bool => $rows->has($member['user_id']))
            ->map(fn (array $member): array => $this->participantEntry($rows[$member['user_id']], $member['role'], null, null, null))
            ->push($this->participantEntry($viewer, $viewer->role, $viewer->left_at, null, null))
            ->values()
            ->all();
    }

    /**
     * One person on the participant list. Without a status (someone who
     * isn't in the group, or a viewer who isn't), they're shown offline with
     * no "last seen".
     *
     * @return array<string, mixed>
     */
    private function participantEntry(ConversationParticipant $participant, string $role, mixed $leftAt, ?PresenceStatus $status, ?User $viewer): array
    {
        return [
            'user_id' => $participant->user_id,
            'name' => $participant->user?->name,
            'avatar_url' => $participant->user?->avatar_url,
            'role' => $role,
            'left_at' => $leftAt,
            'is_online' => $status?->isOnline() ?? false,
            'presence_status' => $status ?? PresenceStatus::Offline,
            'last_seen_at' => $status !== null ? app(LastSeenVisibility::class)->lastSeenFor($viewer, $participant->user) : null,
        ];
    }

    /**
     * The conversation-list preview. For a group the viewer has left, this
     * freezes at the last message they could actually see (by id — see
     * left_at_message_id), rather than whatever the group has said since —
     * otherwise "no new messages will be seen" would be violated by the
     * preview line itself.
     *
     * @return array<string, mixed>|null
     */
    private function resolveLatestMessage(?string $viewerLeftAtMessageId): ?array
    {
        $message = $viewerLeftAtMessageId
            ? $this->messages()->where('id', '<=', $viewerLeftAtMessageId)->latest('id')->first()
            : $this->lastMessage;

        return $message ? $this->formatMessagePreview($message) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMessagePreview(Message $message): array
    {
        return [
            'type' => $message->type,
            'body' => $message->body,
            'sender_name' => $message->sender?->name,
            'event_type' => $message->event_type,
            'metadata' => $message->metadata,
        ];
    }
}
