<?php

namespace App\Http\Resources;

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
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'created_by_user_id' => $this->created_by_user_id,
            'last_message_at' => $this->last_message_at,
            'latest_message' => $this->whenLoaded('latestMessage', function () {
                return $this->latestMessage ? [
                    'body' => $this->latestMessage->body,
                    'sender_name' => $this->latestMessage->sender?->name,
                ] : null;
            }),
            'participants' => $this->whenLoaded('participants', function () {
                return $this->participants->map(fn ($p) => [
                    'user_id' => $p->user_id,
                    'name'    => $p->user?->name,
                    'role'    => $p->role,
                ]);
            }),
            'participants_count' => $this->whenLoaded('participants', fn () => $this->participants->count()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
