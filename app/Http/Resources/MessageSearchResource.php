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
        $viewer = $conversation->participants->firstWhere('user_id', $request->user()->id);

        // A group the viewer has left goes by the title it had then.
        $conversationTitle = $conversation->type === 'group'
            ? ($viewer !== null ? $viewer->titleOf($conversation) : $conversation->title)
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
                'avatar_url' => $this->sender->avatar_url,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
