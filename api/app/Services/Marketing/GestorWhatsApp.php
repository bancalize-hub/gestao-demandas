<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\WaAccount;
use App\Support\Evolution;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * O dono conversando com o gestor pelo WhatsApp.
 *
 * Mensagem que chega no número de avisos vinda do WhatsApp do dono (`alert_phone`) não vira
 * conversa do CRM: vira uma ordem para o gestor, que abre um turno na hora, age se for o caso
 * e responde por lá. Qualquer outro remetente segue o caminho normal (ou é ignorado, no
 * número "só fotos") — só o telefone cadastrado no painel manda no gestor.
 */
class GestorWhatsApp
{
    /**
     * Chamado pelo webhook da Evolution ANTES de qualquer outro tratamento.
     *
     * @param  array<string, mixed>  $m  a mensagem crua do messages.upsert
     * @return bool se a mensagem era do dono (e foi tomada para o gestor)
     */
    public static function receber(array $m, WaAccount $conta): bool
    {
        $key = $m['key'] ?? [];
        if (! empty($key['fromMe'])) {
            return false;
        }
        $jid = (string) ($key['remoteJid'] ?? '');
        $alt = (string) ($key['remoteJidAlt'] ?? '');
        $fone = str_ends_with($jid, '@s.whatsapp.net') ? strtok($jid, '@') : (str_ends_with($alt, '@s.whatsapp.net') ? strtok($alt, '@') : '');
        if ($fone === '') {
            return false;
        }

        $cfg = MarketingAutopilot::withoutGlobalScopes()->where('company_id', $conta->company_id)->whereNotNull('alert_phone')->first();
        if (! $cfg || ! self::mesmoTelefone($fone, (string) $cfg->alert_phone)) {
            return false;
        }

        $waId = (string) ($key['id'] ?? '');
        // A Evolution reentrega o mesmo upsert; sem isto o dono receberia duas respostas.
        if ($waId !== '' && ! Cache::add("gestor-wa:{$waId}", 1, now()->addDay())) {
            return true;
        }

        $msg = self::desembrulhar($m['message'] ?? []);
        $texto = trim((string) ($msg['conversation'] ?? $msg['extendedTextMessage']['text'] ?? $msg['imageMessage']['caption'] ?? ''));
        $audio = isset($msg['audioMessage']);
        $imagem = isset($msg['imageMessage']);
        if ($texto === '' && ! $audio && ! $imagem) {
            return true; // figurinha, reação etc.: não é instrução
        }
        // Respondeu a uma mensagem (ex.: a imagem de um criativo)? Guarda qual.
        $citada = '';
        foreach (['extendedTextMessage', 'imageMessage', 'audioMessage'] as $tipo) {
            $citada = $citada ?: (string) ($msg[$tipo]['contextInfo']['stanzaId'] ?? '');
        }

        MarketingAutopilotEvent::create([
            'company_id' => $conta->company_id,
            'kind' => 'dono',
            'message' => $texto !== '' ? $texto : ($audio ? '[áudio — transcrevendo]' : '[imagem]'),
            'data' => ['wa_id' => $waId, 'audio' => $audio, 'imagem' => $imagem, 'responde_a' => $citada ?: null,
                'instancia' => $conta->instance, 'lido' => false],
        ]);

        self::acordar((int) $conta->company_id);

        return true;
    }

    /** BR com e sem o 9º dígito são o mesmo celular (o WhatsApp entrega dos dois jeitos). */
    public static function mesmoTelefone(string $a, string $b): bool
    {
        $a = preg_replace('/\D/', '', $a);
        $b = preg_replace('/\D/', '', $b);
        if ($a === '' || $b === '') {
            return false;
        }
        $norm = function (string $n) {
            $n = str_starts_with($n, '55') && strlen($n) >= 12 ? substr($n, 2) : $n;

            return substr($n, 0, 2).substr($n, -8); // DDD + 8 últimos
        };

        return $norm($a) === $norm($b);
    }

    /**
     * Baixa a imagem que o dono mandou (exemplo de criativo, print) para a pasta que o gestor
     * e o designer conseguem olhar.
     *
     * @return string|null caminho relativo ao storage
     */
    public static function baixarImagem(MarketingAutopilotEvent $e): ?string
    {
        $base64 = Evolution::mediaBase64((string) ($e->data['wa_id'] ?? ''), $e->data['instancia'] ?? null);
        $bytes = $base64 ? base64_decode($base64, true) : false;
        if (! $bytes) {
            return null;
        }
        $ext = match (true) {
            str_starts_with($bytes, "\x89PNG") => 'png',
            str_starts_with($bytes, 'RIFF') => 'webp',
            default => 'jpg',
        };
        $path = 'marketing/dono/'.now()->format('Ymd-His').'-'.$e->id.'.'.$ext;
        Storage::put($path, $bytes);

        return $path;
    }

    /** Transcreve o áudio do dono (Groq Whisper, o mesmo do atendimento). */
    public static function transcrever(MarketingAutopilotEvent $e): string
    {
        $base64 = Evolution::mediaBase64((string) ($e->data['wa_id'] ?? ''), $e->data['instancia'] ?? null);
        $bytes = $base64 ? base64_decode($base64, true) : false;
        if (! $bytes || ! config('services.groq.key')) {
            return '';
        }
        try {
            $res = Http::withToken((string) config('services.groq.key'))->timeout(90)
                ->attach('file', $bytes, 'audio.ogg')
                ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                    'model' => (string) config('services.groq.whisper_model', 'whisper-large-v3'),
                    'language' => 'pt', 'response_format' => 'json', 'temperature' => 0,
                ]);

            return $res->successful() ? trim((string) $res->json('text')) : '';
        } catch (\Throwable $ex) {
            Log::warning('gestor: transcrição do áudio do dono falhou', ['e' => $ex->getMessage()]);

            return '';
        }
    }

    /**
     * Abre o turno de conversa em segundo plano: o webhook precisa responder à Evolution em
     * milissegundos, e um turno do agente leva minutos. PHP_BINDIR e não PHP_BINARY: no
     * PHP-FPM o PHP_BINARY é o próprio php-fpm, que não roda artisan.
     */
    private static function acordar(int $empresa): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        $artisan = base_path('artisan');
        $log = storage_path('logs/gestor-conversa.log');
        @exec(sprintf('nohup %s %s marketing:gestor-conversa --empresa=%d >> %s 2>&1 &',
            escapeshellarg(PHP_BINDIR.'/php'), escapeshellarg($artisan), $empresa, escapeshellarg($log)));
    }

    /** @param array<string, mixed> $msg */
    private static function desembrulhar(array $msg): array
    {
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'documentWithCaptionMessage', 'editedMessage'] as $w) {
            if (isset($msg[$w]['message']) && is_array($msg[$w]['message'])) {
                return self::desembrulhar($msg[$w]['message']);
            }
        }

        return $msg;
    }
}
