<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    //* Store a new conversation
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
