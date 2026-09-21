<?php

namespace App\Support;

use App\Models\WaAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Acesso à Evolution API (WhatsApp) para etiquetas. Key fica server-side. */
class Evolution
{
    private static function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.evolution.url'), '/'))
            ->withHeaders(['apikey' => (string) config('services.evolution.key')])
            ->timeout(20);
    }

    /** Trilha de envio no canal 'whatsapp' (debug), fora do LOG_LEVEL global. */
    public static function log(string $event, array $ctx = [], string $level = 'info'): void
    {
        try {
            Log::channel('whatsapp')->{$level}($event, $ctx);
        } catch (\Throwable $e) {
            // Log nunca pode derrubar o envio.
        }
    }

    /**
     * POST na Evolution com a trilha completa: endpoint, instância, payload (mídia
     * truncada), status HTTP, corpo e duração. Devolve a Response para o chamador
     * decidir. Sem isto, um 4xx/5xx da Evolution virava `return null` mudo.
     */
    private static function post(string $path, string $instance, array $payload, int $timeout = 20): ?Response
    {
        // Base64 de mídia polui (e estoura) o log: guarda só o tamanho.
        $safe = $payload;
        foreach (['media', 'audio'] as $heavy) {
            if (isset($safe[$heavy])) {
                $safe[$heavy] = '<'.strlen((string) $payload[$heavy]).' bytes base64>';
            }
        }
        if (isset($safe['text'])) {
            $safe['text'] = mb_substr((string) $safe['text'], 0, 120);
        }

        $started = microtime(true);
        self::log('evolution.request', ['path' => $path, 'instance' => $instance, 'payload' => $safe]);

        try {
            $res = self::http()->timeout($timeout)->post($path.'/'.$instance, $payload);
        } catch (\Throwable $e) {
            self::log('evolution.exception', [
                'path' => $path,
                'instance' => $instance,
                'ms' => round((microtime(true) - $started) * 1000),
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ], 'error');

            return null;
        }

        self::log('evolution.response', [
            'path' => $path,
            'instance' => $instance,
            'ms' => round((microtime(true) - $started) * 1000),
            'http' => $res->status(),
            'wa_id' => $res->json('key.id'),
            'body' => mb_substr((string) $res->body(), 0, 600),
        ], $res->successful() ? 'info' : 'error');

        return $res;
    }

    /** Resolve a instância: a passada explicitamente, ou a principal (config) por padrão. */
    private static function instance(?string $instance = null): string
    {
        return self::instanceFor($instance);
    }

    /**
     * Instância-alvo da EMPRESA ATUAL (as queries são escopadas pelo tenant).
     *
     * A ordem importa por causa do multi-provedor: com o principal na API oficial ele
     * não tem instância, e cair direto no default do `.env` mandaria a mensagem pela
     * instância da empresa LEGADA — ou seja, pelo WhatsApp de outra empresa. Por isso
     * o penúltimo passo é o primeiro número Evolution da própria empresa, e o default
     * global só vale para quem ainda não tem número nenhum cadastrado.
     */
    public static function instanceFor(?string $instance = null): string
    {
        if ($instance) {
            return $instance;
        }

        $primary = WaAccount::primary();
        if ($primary?->instance) {
            return $primary->instance;
        }

        $evo = WaAccount::where('provider', 'evolution')
            ->whereNotNull('instance')->orderBy('id')->first();
        if ($evo?->instance) {
            return $evo->instance;
        }

        // Empresa já tem número (só que nenhum na Evolution): sem instância — a chamada
        // falha e fica registrada, em vez de sair pelo número de outro tenant.
        if (WaAccount::query()->exists()) {
            self::log('instancia.indisponivel', [
                'motivo' => 'empresa sem número na Evolution (principal está na API oficial)',
            ], 'warning');

            return '';
        }

        /*
         * Empresa SEM nenhum número: também não sai por ninguém.
         *
         * Aqui havia `config('services.evolution.instance')` — a instância do .env, que é a
         * da empresa 1. Uma empresa recém-cadastrada, antes de conectar o WhatsApp dela,
         * mandava mensagem PELO NÚMERO DA EMPRESA 1: o cliente dela recebia do número errado
         * e a resposta caía na caixa de entrada de outro tenant. Falhar e registrar é o
         * comportamento certo. O EVOLUTION_INSTANCE continua no .env só como semente de
         * migração (criação da primeira conta), nunca como destino de envio.
         */
        self::log('instancia.indisponivel', [
            'motivo' => 'empresa sem nenhum número conectado',
        ], 'warning');

        return '';
    }

    /** Lista as etiquetas do WhatsApp Business conectado. */
    public static function findLabels(?string $instance = null): array
    {
        return self::http()->get('/label/findLabels/'.self::instance($instance))->json() ?? [];
    }

    /** Estado da conexão de uma instância: open | connecting | close. */
    public static function connectionState(?string $instance = null): string
    {
        try {
            return (string) (self::http()->get('/instance/connectionState/'.self::instance($instance))
                ->json('instance.state') ?? 'close');
        } catch (\Throwable $e) {
            return 'close';
        }
    }

    /** Confirma se um número (só dígitos) está no WhatsApp. */
    public static function isOnWhatsApp(string $number, ?string $instance = null): bool
    {
        $number = preg_replace('/\D/', '', $number);
        if ($number === '') {
            return false;
        }
        try {
            $check = self::http()->post('/chat/whatsappNumbers/'.self::instance($instance), [
                'numbers' => [$number],
            ])->json();
            $first = is_array($check) ? ($check[0] ?? null) : null;

            return is_array($first) && ! empty($first['exists']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Envia mídia (imagem/vídeo/documento) pelo WhatsApp. `$base64` é o conteúdo puro
     * (sem o prefixo data:). `$mediatype` ∈ image|video|document. Retorna o wa_id (key.id).
     */
    public static function sendMedia(string $number, string $base64, string $mimetype, string $fileName, string $caption = '', string $mediatype = 'image', ?string $instance = null): ?string
    {
        $number = preg_replace('/\D/', '', $number);
        if ($number === '' || $base64 === '') {
            self::log('sendMedia.abortado', ['number' => $number, 'len_base64' => strlen($base64)], 'error');

            return null;
        }

        $payload = [
            'number' => $number,
            'mediatype' => $mediatype,
            'mimetype' => $mimetype,
            'media' => $base64,
            'fileName' => $fileName,
        ];
        if (trim($caption) !== '') {
            $payload['caption'] = $caption;
        }

        $res = self::post('/message/sendMedia', self::instance($instance), $payload, timeout: 60);

        return $res && $res->successful() ? (string) ($res->json('key.id') ?? '') : null;
    }

    /** Reage a uma mensagem (emoji). `$emoji` vazio remove a reação. Retorna true se ok. */
    public static function sendReaction(string $number, string $waId, string $remoteJid, bool $fromMe, string $emoji, ?string $instance = null): bool
    {
        $number = preg_replace('/\D/', '', $number);
        if ($number === '' || $waId === '') {
            return false;
        }
        try {
            $res = self::http()->post('/message/sendReaction/'.self::instance($instance), [
                'key' => [
                    'id' => $waId,
                    'remoteJid' => $remoteJid ?: ($number.'@s.whatsapp.net'),
                    'fromMe' => $fromMe,
                ],
                'reaction' => $emoji,
            ]);

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('Evolution sendReaction exceção', ['e' => $e->getMessage()]);

            return false;
        }
    }

    /** Envia um áudio como NOTA DE VOZ (PTT) pelo WhatsApp. `$base64` é o áudio puro. Retorna o wa_id. */
    public static function sendAudio(string $number, string $base64, ?string $instance = null): ?string
    {
        $number = preg_replace('/\D/', '', $number);
        if ($number === '' || $base64 === '') {
            self::log('sendAudio.abortado', ['number' => $number, 'len_base64' => strlen($base64)], 'error');

            return null;
        }

        $res = self::post('/message/sendWhatsAppAudio', self::instance($instance), [
            'number' => $number,
            'audio' => $base64,
        ], timeout: 60);

        return $res && $res->successful() ? (string) ($res->json('key.id') ?? '') : null;
    }

    /**
     * Envia uma mensagem de texto para um número (só dígitos).
     * Retorna o wa_id da mensagem enviada (string; '' se a Evolution não devolveu o id),
     * ou null se o envio falhou. O wa_id é usado para casar com o eco do webhook e não duplicar.
     */
    public static function sendText(string $number, string $text, ?string $quotedId = null, string $quotedText = '', ?string $instance = null): ?string
    {
        $raw = $number;
        $number = preg_replace('/\D/', '', $number);
        if ($number === '' || trim($text) === '') {
            self::log('sendText.abortado', [
                'motivo' => $number === '' ? 'numero vazio apos limpar' : 'texto vazio',
                'number_raw' => $raw,
                'len_text' => strlen($text),
            ], 'error');

            return null;
        }

        $payload = ['number' => $number, 'text' => $text];
        // Resposta nativa (quoted): a citação aparece também no WhatsApp do cliente.
        if ($quotedId) {
            $payload['quoted'] = [
                'key' => ['id' => $quotedId],
                'message' => ['conversation' => $quotedText !== '' ? $quotedText : ' '],
            ];
        }

        $res = self::post('/message/sendText', self::instance($instance), $payload);
        if (! $res || ! $res->successful()) {
            return null;
        }

        $waId = (string) ($res->json('key.id') ?? '');
        if ($waId === '') {
            // 2xx sem key.id: o chamador trata '' como sucesso-sem-id e a mensagem fica
            // órfã de recibo (nenhum ack casa com ela). Precisa aparecer no log.
            self::log('sendText.sem_wa_id', ['number' => $number, 'body' => mb_substr((string) $res->body(), 0, 400)], 'error');
        }

        return $waId;
    }

    /**
     * Baixa a mídia (descriptografada) de uma mensagem pelo wa_id e devolve o base64 cru
     * (sem o prefixo data URI), ou null se falhar. Usado para transcrever áudios.
     */
    public static function mediaBase64(string $waId, ?string $instance = null): ?string
    {
        if ($waId === '') {
            return null;
        }
        try {
            $res = self::http()->timeout(40)->post('/chat/getBase64FromMediaMessage/'.self::instance($instance), [
                'message' => ['key' => ['id' => $waId]],
                'convertToMp4' => false,
            ]);
            if (! $res->successful()) {
                return null;
            }

            return $res->json('base64') ?: null;
        } catch (\Throwable $e) {
            Log::warning('Evolution mediaBase64 falhou', ['e' => $e->getMessage()]);

            return null;
        }
    }

    /** Aplica ('add') ou remove ('remove') uma etiqueta de um chat. Best-effort. */
    public static function handleLabel(string $number, string $labelId, string $action, ?string $instance = null): bool
    {
        $number = preg_replace('/\D/', '', $number);
        if ($number === '' || $labelId === '') {
            return false;
        }
        try {
            $res = self::http()->post('/label/handleLabel/'.self::instance($instance), [
                'number' => $number,
                'labelId' => $labelId,
                'action' => $action,
            ]);

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('Evolution handleLabel falhou', ['e' => $e->getMessage()]);

            return false;
        }
    }
}
