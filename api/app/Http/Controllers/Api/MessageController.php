<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

        // WhatsApp: envia de verdade pelo Evolution (não-fatal se falhar).
        if (($data['is_out'] ?? false) && $data['type'] === 'text' && ! empty($data['text'])
            && $conversation->origin === 'WhatsApp' && $conversation->phone) {
            try {
                Http::baseUrl(rtrim((string) config('services.evolution.url'), '/'))
                    ->withHeaders(['apikey' => (string) config('services.evolution.key')])
                    ->timeout(20)
                    ->post('/message/sendText/'.config('services.evolution.instance'), [
                        'number' => preg_replace('/\D/', '', (string) $conversation->phone),
                        'text' => $data['text'],
                    ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $message = $conversation->messages()->create($data);

        // Atualiza o resumo da conversa na lista.
        if ($data['type'] === 'text') {
            $conversation->update([
                'preview' => $data['text'] ?? $conversation->preview,
                'time' => $data['time'] ?? $conversation->time,
                'unread' => ($data['is_out'] ?? false) ? 0 : $conversation->unread,
                'last_message_at' => now(),
            ]);
        }

        return response()->json($message, 201);
    }
}
