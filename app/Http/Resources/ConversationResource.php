<?php

namespace App\Http\Resources;

use App\Models\Message;
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
        $onlineUserIds = $this->relationLoaded('participants')
            ? app(PresenceService::class)->onlineUserIds($this->participants->pluck('user_id')->all())
            : [];

        $viewerParticipant = $this->relationLoaded('participants')
            ? $this->participants->firstWhere('user_id', $request->user()->id)
            : null;
        $viewerLeftAt = $viewerParticipant?->left_at;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'created_by_user_id' => $this->created_by_user_id,
            // A group you've left stops advancing for you — everything below
            // is capped at the moment you left, so no *new* activity leaks in.
            'last_message_at' => $viewerLeftAt && $this->last_message_at
                ? min($this->last_message_at, $viewerLeftAt)
                : $this->last_message_at,
            'viewer_left_at' => $viewerLeftAt,
            'other_participant' => $this->when(
                $this->type === 'direct' && $this->relationLoaded('participants'),
                function () use ($request, $onlineUserIds) {
                    $other = $this->participants->first(fn ($p) => $p->user_id !== $request->user()->id);

                    return $other ? [
                        'id' => $other->user_id,
                        'name' => $other->user?->name,
                        'avatar_url' => $other->user?->avatar_url,
                        'is_online' => in_array($other->user_id, $onlineUserIds, true),
                        'last_seen_at' => $other->user?->last_seen_at,
                    ] : null;
                },
            ),
            'latest_message' => $this->when(
                $this->relationLoaded('participants'),
                fn () => $this->resolveLatestMessage($viewerParticipant?->left_at_message_id),
            ),
            'participants' => $this->whenLoaded('participants', function () use ($onlineUserIds) {
                return $this->participants->map(fn ($p) => [
                    'user_id' => $p->user_id,
                    'name' => $p->user?->name,
                    'avatar_url' => $p->user?->avatar_url,
                    'role' => $p->role,
                    'left_at' => $p->left_at,
                    'is_online' => in_array($p->user_id, $onlineUserIds, true),
                    'last_seen_at' => $p->user?->last_seen_at,
                ]);
            }),
            'participants_count' => $this->whenLoaded(
                'participants',
                fn () => $this->participants->whereNull('left_at')->count(),
            ),
            'unread_count' => $this->when(
                $this->relationLoaded('participants'),
                function () use ($viewerParticipant, $viewerLeftAt) {
                    if (! $viewerParticipant || $viewerLeftAt !== null) {
                        return 0;
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
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
