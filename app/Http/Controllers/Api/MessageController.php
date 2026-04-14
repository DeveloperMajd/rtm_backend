<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Http\Requests\StoreMessageRequest;

class MessageController extends Controller
{
    //* Store a new message in a conversation
    public function store(StoreMessageRequest $request)
    {
        $message = Message::create([
            // 'conversation_id' => $request->conversation_id,
            'conversation_id' => $request->input('conversation_id'),
            // 'sender_user_id' => $request->user()->id,
            'sender_user_id' => $request->input('sender_user_id'),
            'body' => $request->body,
        ]);

        return response()->json($message, 201);
    }
}
