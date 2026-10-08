<?php

namespace App\Http\Resources;

use App\Models\ConversationParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavedMessageResource extends JsonResource
{
    /**
     * One entry in someone's saved list: when they saved it, the message as
     * the history shows it, and the conversation it's in, by name — a
     * group's title, or the other person in a direct conversation (null if
     * their account is gone).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $conversation = $this->message->conversation;

        return [
            'id' => $this->id,
            'saved_at' => $this->created_at,
            'message' => new MessageResource($this->message),
            'conversation' => [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'title' => $conversation->type === 'group'
                    ? $conversation->title
                    : $conversation->participants
                        ->first(fn (ConversationParticipant $participant): bool => $participant->user_id !== $request->user()->id)
                        ?->user?->name,
            ],
        ];
    }
}
