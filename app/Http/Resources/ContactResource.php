<?php

namespace App\Http\Resources;

use App\Services\LastSeenVisibility;
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
        $status = app(PresenceService::class)->statusOf($this->id);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatar_url,
            'is_online' => $status->isOnline(),
            'presence_status' => $status,
            'last_seen_at' => app(LastSeenVisibility::class)->lastSeenFor($request->user(), $this->resource),
        ];
    }
}
