<?php

namespace App\Http\Resources;

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

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'created_by_user_id' => $this->created_by_user_id,
            'last_message_at' => $this->last_message_at,
            'other_participant' => $this->when(
                $this->type === 'direct' && $this->relationLoaded('participants'),
                function () use ($request, $onlineUserIds) {
                    $other = $this->participants->first(fn ($p) => $p->user_id !== $request->user()->id);

                    return $other ? [
                        'id' => $other->user_id,
                        'name' => $other->user?->name,
                        'is_online' => in_array($other->user_id, $onlineUserIds, true),
                        'last_seen_at' => $other->user?->last_seen_at,
                    ] : null;
                },
            ),
            'latest_message' => $this->whenLoaded('lastMessage', function () {
                return $this->lastMessage ? [
                    'body' => $this->lastMessage->body,
                    'sender_name' => $this->lastMessage->sender?->name,
                ] : null;
            }),
            'participants' => $this->whenLoaded('participants', function () use ($onlineUserIds) {
                return $this->participants->map(fn ($p) => [
                    'user_id' => $p->user_id,
                    'name' => $p->user?->name,
                    'role' => $p->role,
                    'is_online' => in_array($p->user_id, $onlineUserIds, true),
                    'last_seen_at' => $p->user?->last_seen_at,
                ]);
            }),
            'participants_count' => $this->whenLoaded('participants', fn () => $this->participants->count()),
            'unread_count' => $this->when(
                $this->relationLoaded('participants'),
                function () use ($request) {
                    $participant = $this->participants->firstWhere('user_id', $request->user()->id);

                    if (! $participant) {
                        return 0;
                    }

                    return $this->messages()
                        ->where('sender_user_id', '!=', $request->user()->id)
                        ->when(
                            $participant->last_read_message_id,
                            fn ($query) => $query->where('id', '>', $participant->last_read_message_id),
                        )
                        ->count();
                },
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
