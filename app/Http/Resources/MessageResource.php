<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
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
            'conversation_id' => $this->conversation_id,
            'body' => $this->body,
            'sender' => $this->whenLoaded('sender', fn () => [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
            ]),
            'reactions' => MessageReactionResource::collection($this->whenLoaded('reactions')),
            'attachments_count' => $this->attachments_count,
            'attachments' => $this->whenLoaded('attachments', fn () => $this->deleted_at
                ? []
                : AttachmentResource::collection($this->attachments)),
            'reply_to_message_id' => $this->reply_to_message_id,
            'reply_to' => $this->whenLoaded('replyTo', fn () => $this->replyTo ? new MessageResource($this->replyTo) : null),
            'edited_at' => $this->edited_at,
            'deleted_at' => $this->deleted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
