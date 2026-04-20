<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{

    public function index(Request $request)
    {
        $conversations = Conversation::whereHas('participants', function ($query) use ($request) {
            $query->where('user_id', 1); // For testing purposes, replace with actual user ID in production
        })->get();

        return response()->json($conversations);
    }

    public function show(Request $request, Conversation $conversation)
    {
        // Check if the user is a participant in the conversation
        $isParticipant = $conversation->participants()->where('user_id', 1)->exists(); // For testing purposes, replace with actual user ID in production

        if (!$isParticipant) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($conversation);
    }


    public function store(Request $request)
    {
        $conversation = Conversation::create([
            // 'created_by_user_id' => $request->user()->id,
            'created_by_user_id' => 1, // For testing purposes, replace with actual user ID in production
            'type' => 'direct',
        ]);

        $conversation->participants()->create([
            // 'user_id' => $request->user()->id,
            'user_id' => 1, // For testing purposes, replace with actual user ID in production
            'role' => 'admin',
            'joined_at' => now(),
            'conversation_id' => $conversation->id,
        ]);

        return response()->json($conversation, 201);
    }
}
