<?php

namespace App\Http\Controllers\Api;

use App\Events\CrmUpdated;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Channels\CloudChannel;
use App\Support\Evolution;
use App\Support\Realtime;
use App\Support\Wa;
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

        $channel = Wa::forConversation($conversation);

        // API oficial: fora da janela de 24h desde a última mensagem do cliente, a Meta só
        // aceita template aprovado. Recusar aqui (com um código que o chat entende) é melhor
        // que gravar a bolha e ver o envio falhar depois, sem o cliente receber nada.
        if ($isOutText && $conversation->origin === 'WhatsApp' && ! $channel->canSendFreeform($conversation->lastInboundTs())) {
            Evolution::log('store.janela_fechada', [
                'conversation_id' => $conversation->id,
                'wa_account_id' => $conversation->wa_account_id ?? null,
            ], 'warning');

            return response()->json([
                'message' => 'A janela de 24h do WhatsApp fechou — para falar com este contato agora, use um template aprovado.',
                'code' => 'window_closed',
            ], 422);
        }

        // WhatsApp: envia de verdade pelo canal do número (Evolution ou Cloud API; não-fatal
        // se falhar) e guarda o wa_id retornado, para que o eco do webhook seja ignorado em
        // vez de duplicar a mensagem.
        $vaiEnviar = $isOutText && $conversation->origin === 'WhatsApp' && $conversation->phone;
        Evolution::log('store.entrada', [
            'conversation_id' => $conversation->id,
            'company_id' => $conversation->company_id,
            'origin' => $conversation->origin,
            'phone' => $conversation->phone,
            'wa_account_id' => $conversation->wa_account_id ?? null,
            'provider' => $conversation->account?->provider ?? 'evolution',
            'instance' => $conversation->account?->instance,
            'type' => $data['type'],
            'is_out' => (bool) ($data['is_out'] ?? false),
            'vai_enviar' => $vaiEnviar,
        ]);

        $waId = null;
        if ($vaiEnviar) {
            try {
                $waId = $channel->sendText((string) $conversation->phone, (string) $data['text'], $data['reply_to'] ?? null, (string) ($data['reply_excerpt'] ?? ''));
            } catch (\Throwable $e) {
                Evolution::log('store.excecao', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'file' => $e->getFile().':'.$e->getLine(),
                ], 'error');
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

        Evolution::log('store.gravada', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'wa_id' => $waId,
            'status' => $data['status'] ?? null,
            'enviou' => $vaiEnviar,
        ], ($vaiEnviar && ! $waId) ? 'error' : 'info');

        // Atualiza o resumo da conversa na lista.
        if ($data['type'] === 'text') {
            $conversation->update([
                'preview' => $data['text'] ?? $conversation->preview,
                'time' => $data['time'] ?? $conversation->time,
                'unread' => ($data['is_out'] ?? false) ? 0 : $conversation->unread,
                'last_message_at' => now(),
            ]);
        }

        // Humano assumiu e escreveu para o lead: a rodada de retomada ativa recomeça do zero
        // (se o lead sumir depois desta mensagem, a IA volta a ter as 3 tentativas).
        if (($data['is_out'] ?? false) && $conversation->nudge_count) {
            $conversation->update(['nudge_count' => 0, 'nudge_last_at' => null]);
        }

        Realtime::messageCreated($message);

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

        $channel = Wa::forConversation($conversation);
        if (! $channel->canSendFreeform($conversation->lastInboundTs())) {
            return response()->json([
                'message' => 'A janela de 24h do WhatsApp fechou — mídia só pode ser enviada dentro dela.',
                'code' => 'window_closed',
            ], 422);
        }

        $waId = $isAudio
            ? $channel->sendAudio((string) $conversation->phone, $base64)
            : $channel->sendMedia((string) $conversation->phone, $base64, $mime, $orig, $caption, $mediatype);
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

        // Guarda o arquivo enviado no cache local de mídia. Na API oficial isso é
        // obrigatório: a Meta não deixa baixar de volta a mídia que NÓS enviamos
        // (o id de upload expira), então sem isso a bolha ficaria sem imagem.
        $this->cacheOutgoingMedia($message, $file->getRealPath(), $mime);

        $preview = $type === 'image' ? '📷 Foto' : ($type === 'video' ? '🎬 Vídeo' : ($type === 'voice' ? '🎵 Áudio' : '📄 '.$orig));
        $conversation->update([
            'preview' => $preview,
            'time' => $data['time'],
            'unread' => 0,
            'last_message_at' => now(),
        ]);

        Realtime::messageCreated($message);

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
        $channel = Wa::forConversation($conversation);                          // canal de destino (por onde sai)
        $srcChannel = $src->conversation ? Wa::forConversation($src->conversation) : $channel; // de onde se baixa a mídia
        if (! $channel->canSendFreeform($conversation->lastInboundTs())) {
            return response()->json([
                'message' => 'A janela de 24h do WhatsApp fechou para esta conversa — não dá para encaminhar agora.',
                'code' => 'window_closed',
            ], 422);
        }
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
            $waId = $channel->sendText($phone, (string) $src->text);
            if (! $waId) {
                return response()->json(['message' => 'Falha ao encaminhar.'], 502);
            }
            $msg = $conversation->messages()->updateOrCreate(['wa_id' => $waId], $base + ['type' => 'text', 'text' => $src->text]);
            $conversation->update(['preview' => mb_substr((string) $src->text, 0, 80), 'time' => $base['time'], 'unread' => 0, 'last_message_at' => now()]);
            Realtime::messageCreated($msg);

            return response()->json($msg, 201);
        }

        // Mídia: baixa o conteúdo da origem (pelo wa_id) e reenvia.
        if (! $src->wa_id) {
            return response()->json(['message' => 'Mídia original indisponível para encaminhar.'], 422);
        }
        $b64 = $srcChannel->mediaBase64((string) $src->wa_id, $src->wa_media_id);
        if (! $b64) {
            return response()->json(['message' => 'Não consegui baixar a mídia original.'], 502);
        }
        $mimeGuess = ['image' => 'image/jpeg', 'video' => 'video/mp4', 'voice' => 'audio/ogg', 'file' => 'application/octet-stream'];
        $mime = (string) ($src->meta ?: ($mimeGuess[$src->type] ?? 'application/octet-stream'));
        $fileName = $src->file_name ?: 'arquivo';

        if ($src->type === 'voice') {
            $waId = $channel->sendAudio($phone, $b64);
        } else {
            $mediatype = $src->type === 'image' ? 'image' : ($src->type === 'video' ? 'video' : 'document');
            $waId = $channel->sendMedia($phone, $b64, $mime, $fileName, (string) ($src->text ?? ''), $mediatype);
        }
        if (! $waId) {
            return response()->json(['message' => 'Falha ao encaminhar a mídia.'], 502);
        }
        $msg = $conversation->messages()->updateOrCreate(['wa_id' => $waId], $base + [
            'type' => $src->type, 'text' => $src->text, 'file_name' => $fileName, 'meta' => $mime, 'dur' => $src->dur,
        ]);
        $preview = $src->type === 'image' ? '📷 Foto' : ($src->type === 'video' ? '🎬 Vídeo' : ($src->type === 'voice' ? '🎵 Áudio' : '📄 '.$fileName));
        $conversation->update(['preview' => $preview, 'time' => $base['time'], 'unread' => 0, 'last_message_at' => now()]);
        Realtime::messageCreated($msg);

        return response()->json($msg, 201);
    }

    /** Reage a uma mensagem (emoji). Envia a reação pelo WhatsApp e guarda no CRM. */
    public function react(Request $request, Conversation $conversation, Message $message)
    {
        abort_unless($message->conversation_id === $conversation->id, 404);
        $data = $request->validate(['emoji' => 'nullable|string|max:16']);
        $emoji = (string) ($data['emoji'] ?? '');

        if ($message->wa_id && $conversation->origin === 'WhatsApp' && $conversation->phone) {
            Wa::forConversation($conversation)->sendReaction((string) $conversation->phone, (string) $message->wa_id, (string) $conversation->wa_jid, (bool) $message->is_out, $emoji);
        }

        $message->update(['reaction' => $emoji !== '' ? $emoji : null]);
        Realtime::messagePatched($message, ['reaction' => $message->reaction]);

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

        $channel = Wa::forConversation($conversation);
        if (! $channel->canSendFreeform($conversation->lastInboundTs())) {
            return response()->json([
                'message' => 'A janela de 24h do WhatsApp fechou — reenvie usando um template aprovado.',
                'code' => 'window_closed',
            ], 422);
        }

        $waId = $channel->sendText(
            (string) $conversation->phone,
            (string) $message->text,
            $message->reply_to,
            (string) ($message->reply_excerpt ?? ''),
        );

        if ($waId === null) {
            return response()->json(['message' => 'O WhatsApp recusou o envio. Tente de novo em alguns minutos.'], 502);
        }

        // O status volta a 'sent'; o webhook messages.update decide o desfecho
        // (delivered/read, ou 'error' de novo se o WhatsApp mandar um nack).
        $message->update(['wa_id' => $waId, 'status' => 'sent']);
        Realtime::messagePatched($message, ['status' => 'sent']);

        return response()->json($message);
    }

    /**
     * Templates aprovados disponíveis para esta conversa + se a janela de 24h está aberta.
     * O chat usa isso para decidir entre a caixa de texto normal e o envio de template.
     */
    public function templates(Conversation $conversation)
    {
        $account = $conversation->account;
        $open = $conversation->canSendFreeform();

        if (! $account?->isCloud()) {
            // Número não-oficial (Evolution) não tem template nem janela.
            return response()->json(['window_open' => $open, 'requires_template' => false, 'templates' => []]);
        }

        $templates = collect((new CloudChannel($account))->templates())
            ->filter(fn ($t) => ($t['status'] ?? '') === 'APPROVED')
            ->map(function ($t) {
                $body = collect($t['components'] ?? [])->firstWhere('type', 'BODY')['text'] ?? '';
                // {{1}}, {{2}}… — quantas variáveis o corpo pede.
                preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $vars);

                return [
                    'name' => $t['name'] ?? '',
                    'language' => $t['language'] ?? 'pt_BR',
                    'category' => $t['category'] ?? '',
                    'body' => $body,
                    'params' => $vars[1] ? max(array_map('intval', $vars[1])) : 0,
                ];
            })
            ->values();

        return response()->json([
            'window_open' => $open,
            'requires_template' => ! $open,
            'templates' => $templates,
        ]);
    }

    /**
     * Envia um template aprovado (o único caminho permitido fora da janela de 24h).
     * Grava a mensagem já com o texto renderizado, para o chat mostrar o que o cliente leu.
     */
    public function sendTemplate(Request $request, Conversation $conversation)
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'language' => 'nullable|string|max:16',
            'params' => 'nullable|array',
            'params.*' => 'string|max:500',
            'body' => 'nullable|string', // corpo cru do template, p/ renderizar o texto salvo
        ]);

        $account = $conversation->account;
        if (! $account?->isCloud()) {
            return response()->json(['message' => 'Templates só existem em números na API oficial da Meta.'], 422);
        }
        if ($conversation->origin !== 'WhatsApp' || ! $conversation->phone) {
            return response()->json(['message' => 'Conversa sem telefone vinculado.'], 422);
        }

        $params = array_values($data['params'] ?? []);
        $waId = (new CloudChannel($account))->sendTemplate(
            (string) $conversation->phone,
            $data['name'],
            $data['language'] ?? 'pt_BR',
            $params,
        );

        if (! $waId) {
            return response()->json(['message' => 'A Meta recusou o envio do template. Confira se ele está aprovado e se as variáveis batem.'], 502);
        }

        // Texto salvo = o template com as variáveis já substituídas (ou o nome, se
        // o corpo não veio junto) — é o que a equipe precisa ver no histórico.
        $text = (string) ($data['body'] ?? '');
        foreach ($params as $i => $v) {
            $text = str_replace(['{{'.($i + 1).'}}', '{{ '.($i + 1).' }}'], (string) $v, $text);
        }
        $text = trim($text) !== '' ? $text : '[template] '.$data['name'];

        $message = $conversation->messages()->updateOrCreate(['wa_id' => $waId], [
            'type' => 'text',
            'is_out' => true,
            'text' => $text,
            'status' => 'sent',
            'time' => now()->format('H:i'),
            'ts' => time(),
            'position' => ($conversation->messages()->max('position') ?? -1) + 1,
        ]);

        $conversation->update([
            'preview' => mb_substr($text, 0, 80),
            'time' => $message->time,
            'unread' => 0,
            'last_message_at' => now(),
        ]);

        Realtime::messageCreated($message);

        return response()->json($message, 201);
    }

    /**
     * Copia a mídia recém-enviada para o cache local (storage/app/wa-media/{id}), que é
     * de onde WhatsAppController::media serve os arquivos. Best-effort: falhar aqui só
     * significa que a bolha vai tentar baixar do provedor depois.
     */
    private function cacheOutgoingMedia(Message $message, string $sourcePath, string $mime): void
    {
        try {
            $dir = storage_path('app/wa-media');
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (@copy($sourcePath, "{$dir}/{$message->id}")) {
                @file_put_contents("{$dir}/{$message->id}.mime", $mime);
            }
        } catch (\Throwable $e) {
            // silencioso de propósito
        }
    }

    /** Apaga uma mensagem do CRM. (Não remove no WhatsApp do cliente.) */
    public function destroy(Conversation $conversation, Message $message)
    {
        abort_unless($message->conversation_id === $conversation->id, 404);

        $message->delete();
        Realtime::messagePatched($message, ['removed' => true]);

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
            broadcast(new CrmUpdated('conversation', $conversation->company_id, $conversation->slug))->toOthers();
        } catch (\Throwable $e) {
        }

        return response()->noContent();
    }
}
