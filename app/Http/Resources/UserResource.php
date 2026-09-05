<?php

namespace App\Http\Resources;

use App\Services\PresenceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_online' => app(PresenceService::class)->isOnline($this->id),
            'last_seen_at' => $this->last_seen_at,
        ];
    }
}
