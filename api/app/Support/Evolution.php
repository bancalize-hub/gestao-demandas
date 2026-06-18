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

    private static function instance(): string
    {
        return (string) config('services.evolution.instance');
    }

    /** Lista as etiquetas do WhatsApp Business conectado. */
    public static function findLabels(): array
    {
        return self::http()->get('/label/findLabels/'.self::instance())->json() ?? [];
    }

    /**
     * Envia uma mensagem de texto para um número (só dígitos).
     * Retorna o wa_id da mensagem enviada (string; '' se a Evolution não devolveu o id),
     * ou null se o envio falhou. O wa_id é usado para casar com o eco do webhook e não duplicar.
     */
    public static function sendText(string $number, string $text): ?string
    {
        $number = preg_replace('/\D/', '', $number);
        if ($number === '' || trim($text) === '') {
            return null;
        }
        try {
            $res = self::http()->post('/message/sendText/'.self::instance(), [
                'number' => $number,
                'text' => $text,
            ]);
            if (! $res->successful()) {
                return null;
            }

            return (string) ($res->json('key.id') ?? '');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Evolution sendText falhou', ['e' => $e->getMessage()]);

            return null;
        }
    }

    /** Aplica ('add') ou remove ('remove') uma etiqueta de um chat. Best-effort. */
    public static function handleLabel(string $number, string $labelId, string $action): bool
    {
        $number = preg_replace('/\D/', '', $number);
        if ($number === '' || $labelId === '') {
            return false;
        }
        try {
            $res = self::http()->post('/label/handleLabel/'.self::instance(), [
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
