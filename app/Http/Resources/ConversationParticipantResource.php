<?php

namespace App\Http\Resources;

use App\Services\PresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationParticipantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'avatar_url' => $this->user?->avatar_url,
            'role' => $this->role,
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
            'is_online' => app(PresenceService::class)->isOnline($this->user_id),
            'last_seen_at' => $this->user?->last_seen_at,
        ];
    }
}
