<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function store(Request $request, Conversation $conversation)
    {
        $data = $request->validate([
            'type' => 'nullable|in:divider,text,voice,file',
            'is_out' => 'boolean',
            'text' => 'nullable|string',
            'time' => 'nullable|string|max:16',
            'dur' => 'nullable|string|max:16',
            'file_name' => 'nullable|string',
            'meta' => 'nullable|string',
            'label' => 'nullable|string',
        ]);

        $data['type'] = $data['type'] ?? 'text';
        $data['position'] = ($conversation->messages()->max('position') ?? -1) + 1;

        $message = $conversation->messages()->create($data);

        // Atualiza o resumo da conversa na lista.
        if ($data['type'] === 'text') {
            $conversation->update([
                'preview' => $data['text'] ?? $conversation->preview,
                'time' => $data['time'] ?? $conversation->time,
                'unread' => ($data['is_out'] ?? false) ? 0 : $conversation->unread,
            ]);
        }

        return response()->json($message, 201);
    }
}
