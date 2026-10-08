<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The gate shared by every endpoint that reads a conversation's history —
 * its messages, and the photos and files in them.
 */
trait GuardsConversationHistory
{
    /**
     * In the same order and shape as ConversationController::show(): a
     * deleted group is gone for everyone (404), and someone who was never
     * in the conversation gets 403. A member who left passes — they keep
     * read access to the history up to the moment they left, which
     * Message::visibleTo() enforces.
     */
    protected function denyUnlessReadable(Request $request, Conversation $conversation): ?JsonResponse
    {
        if ($conversation->deleted_at !== null) {
            return response()->json(['data' => ['message' => 'Conversation not found']], 404);
        }

        $isParticipant = $conversation->participants()->where('user_id', $request->user()->id)->exists();

        if (! $isParticipant) {
            return response()->json(['data' => ['message' => 'Forbidden']], 403);
        }

        return null;
    }
}
