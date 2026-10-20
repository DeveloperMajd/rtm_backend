<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated user's own profile — includes fields (email, bio) that
 * are not exposed about other users.
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Null until the address is confirmed: the app shows "Check
            // your email" instead of itself until then.
            'email_verified_at' => $this->email_verified_at,
            'avatar_url' => $this->avatar_url,
            'bio' => $this->bio,
        ];
    }
}
