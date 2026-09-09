<?php

namespace App\Http\Resources;

use App\Services\PresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person in the current user's contact list. Wraps a User model.
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatar_url,
            'is_online' => app(PresenceService::class)->isOnline($this->id),
            'last_seen_at' => $this->last_seen_at,
        ];
    }
}
