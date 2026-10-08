<?php

namespace App\Http\Resources;

use App\Services\LastSeenVisibility;
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
        $status = app(PresenceService::class)->statusOf($this->user_id);

        return [
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'avatar_url' => $this->user?->avatar_url,
            'role' => $this->role,
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
            'is_online' => $status->isOnline(),
            'presence_status' => $status,
            'last_seen_at' => app(LastSeenVisibility::class)->lastSeenFor($request->user(), $this->user),
        ];
    }
}
