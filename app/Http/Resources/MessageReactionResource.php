<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageReactionResource extends JsonResource
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
            'reaction' => $this->reaction,
            // Who reacted, by name, and no more: a message carries every
            // reaction to it, and its broadcast goes out again whenever one
            // changes. Their avatar URL went with each, unused, and was about
            // 40% of that broadcast, already the largest the app sends.
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
        ];
    }
}
