<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageSearchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $conversation = $this->conversation;

        $conversationTitle = $conversation->type === 'group'
            ? $conversation->title
            : $conversation->participants
                ->first(fn ($participant) => $participant->user_id !== $request->user()->id)
                ?->user?->name;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'conversation_title' => $conversationTitle,
            'body' => $this->body,
            'sender' => [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
