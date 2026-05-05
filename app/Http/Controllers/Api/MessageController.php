<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Conversation;
use Illuminate\Http\Request;

class MessageController extends Controller
{

    //* Read messages for a conversation ordered by created_at
    public function index(Request $request, Conversation $conversation)
    {
        $per_page = $request->query('per_page', 20);

        $messages = $conversation->messages()->with('sender:id,name')->orderBy('created_at', 'asc')->paginate($per_page);
        return response()->json($messages);
    }

    //* Store a new message in a conversation
    public function store(StoreMessageRequest $request)
    {
        $message = Message::create([
            'conversation_id' => $request->input('conversation_id'),
            'sender_user_id' => $request->input('sender_user_id'),
            'body' => $request->body,
        ]);

        return response()->json($message, 201);
    }
}
