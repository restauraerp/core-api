<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;

class ChatMessageController extends Controller
{
    public function index()
    {
        return response()->json(ChatMessage::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            
        ]);

        $chatMessage = ChatMessage::create($validated);
        return response()->json($chatMessage, 201);
    }

    public function show(ChatMessage $chatMessage)
    {
        return response()->json($chatMessage);
    }

    public function update(Request $request, ChatMessage $chatMessage)
    {
        $validated = $request->validate([
            
        ]);

        $chatMessage->update($validated);
        return response()->json($chatMessage);
    }

    public function destroy(ChatMessage $chatMessage)
    {
        $chatMessage->delete();
        return response()->json(null, 204);
    }
}