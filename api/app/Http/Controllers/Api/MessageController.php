<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Evolution;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    /**
     * Página da thread (keyset). before_ts/before_id → mensagens mais ANTIGAS que o
     * limite ("carregar anteriores"); after_ts/after_id → mais NOVAS (delta ao voltar
     * pra uma conversa em cache). Sempre em ordem cronológica ascendente, colunas enxutas.
     */
    public function index(Request $request, Conversation $conversation)
    {
        $limit = min(500, max(1, (int) $request->query('limit', 150)));
        $q = $conversation->messages()->reorder();

        if ($request->filled('after_id')) {
            $ts = (int) $request->query('after_ts', 0);
            $id = (int) $request->query('after_id');
            $rows = $q->where(function ($w) use ($ts, $id) {
                $w->where('ts', '>', $ts)->orWhere(fn ($w2) => $w2->where('ts', $ts)->where('id', '>', $id));
            })->orderBy('ts')->orderBy('id')->limit($limit)->get(Message::THREAD_COLUMNS);

            return response()->json(['messages' => $rows, 'has_more' => false]);
        }

        $ts = $request->query('before_ts');
        $id = (int) $request->query('before_id', 0);
        $q->where(function ($w) use ($ts, $id) {
            if ($ts === null || $ts === '') {
                // Limite sem ts (legado): mais antigas = ids menores entre as sem ts.
                $w->whereNull('ts')->where('id', '<', $id);

                return;
            }
            // Sem ts = legado mais antigo que qualquer ts — sempre entra no "anteriores".
            $w->where('ts', '<', (int) $ts)
                ->orWhere(fn ($w2) => $w2->where('ts', (int) $ts)->where('id', '<', $id))
                ->orWhereNull('ts');
        })->orderByDesc('ts')->orderByDesc('id');

        $rows = $q->limit($limit + 1)->get(Message::THREAD_COLUMNS);
        $hasMore = $rows->count() > $limit;

        return response()->json([
            'messages' => $rows->take($limit)->reverse()->values(),
            'has_more' => $hasMore,
        ]);
    }

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
            'reply_to' => 'nullable|string',       // wa_id da mensagem citada
            'reply_excerpt' => 'nullable|string',  // trecho exibido no balão de citação
        ]);

        $data['type'] = $data['type'] ?? 'text';
        $data['position'] = ($conversation->messages()->max('position') ?? -1) + 1;
        $data['ts'] = time();
        if (isset($data['reply_excerpt'])) {
            $data['reply_excerpt'] = mb_substr((string) $data['reply_excerpt'], 0, 200);
        }

        $isOutText = ($data['is_out'] ?? false) && $data['type'] === 'text' && ! empty($data['text']);

        // Conversa de WhatsApp sem telefone (ex.: @lid que não resolveu o número) não tem
        // para onde enviar. Sem esta guarda a mensagem era gravada, devolvia 201 e aparecia
        // normal no chat — sem nunca sair. Recusa igual storeMedia() em vez de mensagem fantasma.
        if ($isOutText && $conversation->origin === 'WhatsApp' && ! $conversation->phone) {
            return response()->json([
                'message' => 'Conversa sem telefone vinculado — não dá para enviar pelo WhatsApp.',
            ], 422);
        }

        // WhatsApp: envia de verdade pelo Evolution (não-fatal se falhar) e guarda o wa_id
        // retornado, para que o eco do webhook seja ignorado em vez de duplicar a mensagem.
        $waId = null;
        if ($isOutText && $conversation->origin === 'WhatsApp' && $conversation->phone) {
            try {
                $waId = Evolution::sendText((string) $conversation->phone, (string) $data['text'], $data['reply_to'] ?? null, (string) ($data['reply_excerpt'] ?? ''), instance: $conversation->account?->instance);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Recibo: 'sent' quando o WhatsApp aceitou (tem wa_id); 'pending' se ainda não confirmou.
        // O webhook messages.update depois promove para delivered/read.
        if (($data['is_out'] ?? false) && $conversation->origin === 'WhatsApp' && $conversation->phone) {
            $data['status'] = $waId ? 'sent' : 'pending';
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

        \App\Support\Realtime::messageCreated($message);

        return response()->json($message, 201);
    }

    /**
     * Envia mídia (imagem/vídeo/documento) pelo WhatsApp e grava a mensagem.
     * Falha de envio retorna 502 (não grava mensagem fantasma).
     */
    public function storeMedia(Request $request, Conversation $conversation)
    {
        $request->validate([
            'file' => 'required|file|max:30720', // até 30MB
            'caption' => 'nullable|string',
            'dur' => 'nullable|string|max:16',
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $orig = $file->getClientOriginalName() ?: 'arquivo';
        $caption = trim((string) $request->input('caption', ''));
        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));

        $isAudio = str_starts_with($mime, 'audio/');
        $mediatype = $isAudio ? 'audio'
            : (str_starts_with($mime, 'image/') ? 'image'
            : (str_starts_with($mime, 'video/') ? 'video' : 'document'));
        // tipo interno: voice (nota de voz) | image | video | file
        $type = $isAudio ? 'voice' : ($mediatype === 'document' ? 'file' : $mediatype);

        if ($conversation->origin !== 'WhatsApp' || ! $conversation->phone) {
            return response()->json(['message' => 'Conversa sem WhatsApp vinculado para enviar mídia.'], 422);
        }

        $inst = $conversation->account?->instance;
        $waId = $isAudio
            ? Evolution::sendAudio((string) $conversation->phone, $base64, instance: $inst)
            : Evolution::sendMedia((string) $conversation->phone, $base64, $mime, $orig, $caption, $mediatype, instance: $inst);
        if (! $waId) {
            return response()->json(['message' => 'Falha ao enviar a mídia pelo WhatsApp. Tente novamente.'], 502);
        }

        $data = [
            'type' => $type,
            'is_out' => true,
            'text' => $caption !== '' ? $caption : null,
            'file_name' => $orig,
            'meta' => $mime,
            'dur' => $isAudio ? ($request->input('dur') ?: null) : null,
            'time' => now()->format('H:i'),
            'ts' => time(),
            'status' => 'sent',
            'position' => ($conversation->messages()->max('position') ?? -1) + 1,
        ];
        $message = $conversation->messages()->updateOrCreate(['wa_id' => $waId], $data);

        $preview = $type === 'image' ? '📷 Foto' : ($type === 'video' ? '🎬 Vídeo' : ($type === 'voice' ? '🎵 Áudio' : '📄 '.$orig));
        $conversation->update([
            'preview' => $preview,
            'time' => $data['time'],
            'unread' => 0,
            'last_message_at' => now(),
        ]);

        \App\Support\Realtime::messageCreated($message);

        return response()->json($message, 201);
    }

    /**
     * Encaminha uma mensagem (texto ou mídia) para esta conversa (o destino é a rota).
     * Texto → sendText; mídia → baixa o base64 da origem e reenvia.
     */
    public function forward(Request $request, Conversation $conversation)
    {
        $data = $request->validate(['message_id' => 'required|integer']);
        $src = Message::find($data['message_id']);
        abort_unless((bool) $src, 404);

        if ($conversation->origin !== 'WhatsApp' || ! $conversation->phone) {
            return response()->json(['message' => 'Conversa de destino sem WhatsApp vinculado.'], 422);
        }
        $phone = (string) $conversation->phone;
        $inst = $conversation->account?->instance;          // número de destino (de onde sai)
        $srcInst = $src->conversation?->account?->instance;  // número de origem (de onde se baixa a mídia)
        $now = time();
        $base = [
            'is_out' => true,
            'time' => now()->format('H:i'),
            'ts' => $now,
            'status' => 'sent',
            'position' => ($conversation->messages()->max('position') ?? -1) + 1,
        ];

        // Texto puro.
        if ($src->type === 'text') {
            if (trim((string) $src->text) === '') {
                return response()->json(['message' => 'Nada para encaminhar.'], 422);
            }
            $waId = Evolution::sendText($phone, (string) $src->text, instance: $inst);
            if (! $waId) {
                return response()->json(['message' => 'Falha ao encaminhar.'], 502);
            }
            $msg = $conversation->messages()->updateOrCreate(['wa_id' => $waId], $base + ['type' => 'text', 'text' => $src->text]);
            $conversation->update(['preview' => mb_substr((string) $src->text, 0, 80), 'time' => $base['time'], 'unread' => 0, 'last_message_at' => now()]);
            \App\Support\Realtime::messageCreated($msg);

            return response()->json($msg, 201);
        }

        // Mídia: baixa o conteúdo da origem (pelo wa_id) e reenvia.
        if (! $src->wa_id) {
            return response()->json(['message' => 'Mídia original indisponível para encaminhar.'], 422);
        }
        $b64 = Evolution::mediaBase64((string) $src->wa_id, instance: $srcInst);
        if (! $b64) {
            return response()->json(['message' => 'Não consegui baixar a mídia original.'], 502);
        }
        $mimeGuess = ['image' => 'image/jpeg', 'video' => 'video/mp4', 'voice' => 'audio/ogg', 'file' => 'application/octet-stream'];
        $mime = (string) ($src->meta ?: ($mimeGuess[$src->type] ?? 'application/octet-stream'));
        $fileName = $src->file_name ?: 'arquivo';

        if ($src->type === 'voice') {
            $waId = Evolution::sendAudio($phone, $b64, instance: $inst);
        }
        else {
            $mediatype = $src->type === 'image' ? 'image' : ($src->type === 'video' ? 'video' : 'document');
            $waId = Evolution::sendMedia($phone, $b64, $mime, $fileName, (string) ($src->text ?? ''), $mediatype, instance: $inst);
        }
        if (! $waId) {
            return response()->json(['message' => 'Falha ao encaminhar a mídia.'], 502);
        }
        $msg = $conversation->messages()->updateOrCreate(['wa_id' => $waId], $base + [
            'type' => $src->type, 'text' => $src->text, 'file_name' => $fileName, 'meta' => $mime, 'dur' => $src->dur,
        ]);
        $preview = $src->type === 'image' ? '📷 Foto' : ($src->type === 'video' ? '🎬 Vídeo' : ($src->type === 'voice' ? '🎵 Áudio' : '📄 '.$fileName));
        $conversation->update(['preview' => $preview, 'time' => $base['time'], 'unread' => 0, 'last_message_at' => now()]);
        \App\Support\Realtime::messageCreated($msg);

        return response()->json($msg, 201);
    }

    /** Reage a uma mensagem (emoji). Envia a reação pelo WhatsApp e guarda no CRM. */
    public function react(Request $request, Conversation $conversation, Message $message)
    {
        abort_unless($message->conversation_id === $conversation->id, 404);
        $data = $request->validate(['emoji' => 'nullable|string|max:16']);
        $emoji = (string) ($data['emoji'] ?? '');

        if ($message->wa_id && $conversation->origin === 'WhatsApp' && $conversation->phone) {
            Evolution::sendReaction((string) $conversation->phone, (string) $message->wa_id, (string) $conversation->wa_jid, (bool) $message->is_out, $emoji, instance: $conversation->account?->instance);
        }

        $message->update(['reaction' => $emoji !== '' ? $emoji : null]);
        \App\Support\Realtime::messagePatched($message, ['reaction' => $message->reaction]);

        return response()->json($message);
    }

    /**
     * Reenvia uma mensagem de saída que o WhatsApp recusou (status 'error', ex.: ack 463)
     * ou que nunca chegou a sair ('pending'/sem status). Usa o texto já gravado e
     * substitui o wa_id da linha pelo novo, para o eco do webhook casar com ela em vez
     * de duplicar. Mídia não é reenviável: o conteúdo original vive no servidor do
     * WhatsApp e, se o envio falhou, não há o que baixar.
     */
    public function resend(Conversation $conversation, Message $message)
    {
        abort_unless($message->conversation_id === $conversation->id, 404);

        if (! $message->is_out) {
            return response()->json(['message' => 'Só dá para reenviar mensagem enviada por você.'], 422);
        }
        if (in_array($message->status, ['delivered', 'read'], true)) {
            return response()->json(['message' => 'Esta mensagem já foi entregue.'], 422);
        }
        if ($message->type !== 'text' || trim((string) $message->text) === '') {
            return response()->json(['message' => 'Só mensagens de texto podem ser reenviadas.'], 422);
        }
        if ($conversation->origin !== 'WhatsApp' || ! $conversation->phone) {
            return response()->json(['message' => 'Conversa sem telefone vinculado — não dá para enviar pelo WhatsApp.'], 422);
        }

        $waId = Evolution::sendText(
            (string) $conversation->phone,
            (string) $message->text,
            $message->reply_to,
            (string) ($message->reply_excerpt ?? ''),
            instance: $conversation->account?->instance,
        );

        if ($waId === null) {
            return response()->json(['message' => 'O WhatsApp recusou o envio. Tente de novo em alguns minutos.'], 502);
        }

        // O status volta a 'sent'; o webhook messages.update decide o desfecho
        // (delivered/read, ou 'error' de novo se o WhatsApp mandar um nack).
        $message->update(['wa_id' => $waId, 'status' => 'sent']);
        \App\Support\Realtime::messagePatched($message, ['status' => 'sent']);

        return response()->json($message);
    }

    /** Apaga uma mensagem do CRM. (Não remove no WhatsApp do cliente.) */
    public function destroy(Conversation $conversation, Message $message)
    {
        abort_unless($message->conversation_id === $conversation->id, 404);

        $message->delete();
        \App\Support\Realtime::messagePatched($message, ['removed' => true]);

        // Recalcula o resumo da conversa com a última mensagem de texto restante.
        $last = $conversation->messages()
            ->where('type', 'text')->whereNotNull('text')
            ->reorder()->orderByDesc('ts')->orderByDesc('id')->first();

        $conversation->update([
            'preview' => $last ? mb_substr((string) $last->text, 0, 80) : null,
            'time' => $last->time ?? $conversation->time,
        ]);

        // preview/time são mudanças "quietas" (não broadcastam no saved) — aqui a
        // origem é uma deleção, então avisa explicitamente p/ as outras abas.
        try {
            broadcast(new \App\Events\CrmUpdated('conversation', $conversation->company_id, $conversation->slug))->toOthers();
        } catch (\Throwable $e) {
        }

        return response()->noContent();
    }
}
