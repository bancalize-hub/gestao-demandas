<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Acesso à Evolution API (WhatsApp) para etiquetas. Key fica server-side. */
class Evolution
{
    private static function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.evolution.url'), '/'))
            ->withHeaders(['apikey' => (string) config('services.evolution.key')])
            ->timeout(20);
    }

    /** Resolve a instância: a passada explicitamente, ou a principal (config) por padrão. */
    private static function instance(?string $instance = null): string
    {
        return $instance ?: (string) config('services.evolution.instance');
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
            return null;
        }
        try {
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
            $res = self::http()->timeout(60)->post('/message/sendMedia/'.self::instance($instance), $payload);
            if (! $res->successful()) {
                \Illuminate\Support\Facades\Log::warning('Evolution sendMedia falhou', ['status' => $res->status(), 'body' => mb_substr((string) $res->body(), 0, 300)]);

                return null;
            }

            return (string) ($res->json('key.id') ?? '');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Evolution sendMedia exceção', ['e' => $e->getMessage()]);

            return null;
        }
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
            \Illuminate\Support\Facades\Log::warning('Evolution sendReaction exceção', ['e' => $e->getMessage()]);

            return false;
        }
    }

    /** Envia um áudio como NOTA DE VOZ (PTT) pelo WhatsApp. `$base64` é o áudio puro. Retorna o wa_id. */
    public static function sendAudio(string $number, string $base64, ?string $instance = null): ?string
    {
        $number = preg_replace('/\D/', '', $number);
        if ($number === '' || $base64 === '') {
            return null;
        }
        try {
            $res = self::http()->timeout(60)->post('/message/sendWhatsAppAudio/'.self::instance($instance), [
                'number' => $number,
                'audio' => $base64,
            ]);
            if (! $res->successful()) {
                \Illuminate\Support\Facades\Log::warning('Evolution sendAudio falhou', ['status' => $res->status(), 'body' => mb_substr((string) $res->body(), 0, 300)]);

                return null;
            }

            return (string) ($res->json('key.id') ?? '');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Evolution sendAudio exceção', ['e' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Envia uma mensagem de texto para um número (só dígitos).
     * Retorna o wa_id da mensagem enviada (string; '' se a Evolution não devolveu o id),
     * ou null se o envio falhou. O wa_id é usado para casar com o eco do webhook e não duplicar.
     */
    public static function sendText(string $number, string $text, ?string $quotedId = null, string $quotedText = '', ?string $instance = null): ?string
    {
        $number = preg_replace('/\D/', '', $number);
        if ($number === '' || trim($text) === '') {
            return null;
        }
        try {
            $payload = [
                'number' => $number,
                'text' => $text,
            ];
            // Resposta nativa (quoted): a citação aparece também no WhatsApp do cliente.
            if ($quotedId) {
                $payload['quoted'] = [
                    'key' => ['id' => $quotedId],
                    'message' => ['conversation' => $quotedText !== '' ? $quotedText : ' '],
                ];
            }
            $res = self::http()->post('/message/sendText/'.self::instance($instance), $payload);
            if (! $res->successful()) {
                return null;
            }

            return (string) ($res->json('key.id') ?? '');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Evolution sendText falhou', ['e' => $e->getMessage()]);

            return null;
        }
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
            \Illuminate\Support\Facades\Log::warning('Evolution mediaBase64 falhou', ['e' => $e->getMessage()]);

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
            \Illuminate\Support\Facades\Log::warning('Evolution handleLabel falhou', ['e' => $e->getMessage()]);

            return false;
        }
    }
}
