<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Evolution;
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
        $data['ts'] = time();

        // WhatsApp: envia de verdade pelo Evolution (não-fatal se falhar) e guarda o wa_id
        // retornado, para que o eco do webhook seja ignorado em vez de duplicar a mensagem.
        $waId = null;
        if (($data['is_out'] ?? false) && $data['type'] === 'text' && ! empty($data['text'])
            && $conversation->origin === 'WhatsApp' && $conversation->phone) {
            try {
                $waId = Evolution::sendText((string) $conversation->phone, (string) $data['text']);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Grava já com o wa_id; updateOrCreate fecha a corrida caso o eco tenha chegado primeiro.
        $message = $waId
            ? $conversation->messages()->updateOrCreate(['wa_id' => $waId], $data)
            : $conversation->messages()->create($data);

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

    /** Apaga uma mensagem do CRM. (Não remove no WhatsApp do cliente.) */
    public function destroy(Conversation $conversation, Message $message)
    {
        abort_unless($message->conversation_id === $conversation->id, 404);

        $message->delete();

        // Recalcula o resumo da conversa com a última mensagem de texto restante.
        $last = $conversation->messages()
            ->where('type', 'text')->whereNotNull('text')
            ->reorder()->orderByDesc('ts')->orderByDesc('id')->first();

        $conversation->update([
            'preview' => $last ? mb_substr((string) $last->text, 0, 80) : null,
            'time' => $last->time ?? $conversation->time,
        ]);

        return response()->noContent();
    }
}
