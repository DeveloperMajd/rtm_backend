<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A photo or file in a conversation's shared media: the attachment as a
 * message carries it, and who sent it when, since a shared-media list
 * gathers attachments from many messages (the viewer shows each one's).
 *
 * @mixin Attachment
 */
class SharedAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sender = $this->message->sender;

        return [
            ...(new AttachmentResource($this->resource))->toArray($request),
            'sender' => $sender ? [
                'id' => $sender->id,
                'name' => $sender->name,
                'avatar_url' => $sender->avatar_url,
            ] : null,
            'sent_at' => $this->message->created_at,
        ];
    }
}
