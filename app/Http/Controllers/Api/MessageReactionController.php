<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageReactionUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageReactionRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MessageReactionController extends Controller
{
    public function store(StoreMessageReactionRequest $request, Message $message): MessageResource
    {
        $message->reactions()->firstOrCreate([
            'user_id' => $request->user()->id,
            'reaction' => $request->input('reaction'),
        ]);

        $message->load(['sender:id,name,avatar_url', 'reactions.user:id,name,avatar_url', 'attachments']);

        broadcast(new MessageReactionUpdated($message));

        return new MessageResource($message);
    }

    public function destroy(Request $request, Message $message, string $reaction): Response
    {
        $isParticipant = $message->conversation->participants()
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $isParticipant) {
            return response()->noContent(403);
        }

        $message->reactions()
            ->where('user_id', $request->user()->id)
            ->where('reaction', $reaction)
            ->delete();

        $message->load(['sender:id,name,avatar_url', 'reactions.user:id,name,avatar_url', 'attachments']);

        broadcast(new MessageReactionUpdated($message));

        return response()->noContent();
    }
}
