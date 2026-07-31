<?php

namespace App\Services;

use App\Models\Message;
use App\Support\Claude;
use App\Support\Realtime;
use App\Support\Wa;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transcreve áudios (voice) do WhatsApp via Groq Whisper (free tier, compatível com a API
 * da OpenAI). Pega os bytes do áudio no Evolution e devolve o texto em pt-BR. A IA passa a
 * "ouvir" os áudios do cliente (ver AiReplyService / AutoReplyTick).
 */
class TranscriptionService
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/audio/transcriptions';

    public function enabled(): bool
    {
        return (bool) config('services.groq.key');
    }

    /**
     * Transcreve a mensagem de áudio e grava em message.transcript. Conta a tentativa
     * (trava de retry). Retorna o texto, ou null se não deu (sem chave, mídia ausente, falha).
     */
    public function transcribe(Message $message): ?string
    {
        if (! $this->enabled() || $message->type !== 'voice' || ! $message->wa_id) {
            return null;
        }

        // Conta a tentativa antes de tudo — evita loop infinito se a mídia/serviço falhar.
        $message->increment('transcribe_attempts');

        $base64 = Wa::for($message->conversation?->account)->mediaBase64($message->wa_id, $message->wa_media_id);
        if (! $base64) {
            return null;
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        try {
            $res = Http::withToken((string) config('services.groq.key'))
                ->timeout(90)
                ->attach('file', $bytes, 'audio.ogg')
                ->post(self::ENDPOINT, [
                    'model' => (string) config('services.groq.whisper_model', 'whisper-large-v3'),
                    'language' => 'pt',
                    'response_format' => 'json',
                    'temperature' => 0,
                ]);

            if (! $res->successful()) {
                Log::warning('Groq transcrição falhou', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 200)]);

                return null;
            }

            $text = trim((string) $res->json('text'));
            if ($text === '') {
                // Áudio sem fala reconhecível: grava vazio p/ não ficar re-tentando pra sempre.
                $message->update(['transcript' => '']);

                return '';
            }

            $message->update(['transcript' => $text]);
            // Transcrição pronta aparece na bolha em tempo real.
            Realtime::messagePatched($message, ['transcript' => $text]);

            return $text;
        } catch (\Throwable $e) {
            Log::warning('Groq transcrição exceção', ['e' => $e->getMessage()]);

            return null;
        }
    }

    public function visionEnabled(): bool
    {
        return (bool) config('services.claude.oauth_token');
    }

    /**
     * Descreve uma imagem do WhatsApp via Claude (visão) e grava em message.transcript —
     * a IA passa a "ver" as imagens do cliente (conta de luz, documento, foto), igual
     * ela "ouve" os áudios. Conta a tentativa (trava de retry). Retorna a descrição ou null.
     */
    public function describeImage(Message $message): ?string
    {
        if (! $this->visionEnabled() || $message->type !== 'image' || ! $message->wa_id) {
            return null;
        }

        $message->increment('transcribe_attempts');

        $base64 = Wa::for($message->conversation?->account)->mediaBase64($message->wa_id, $message->wa_media_id);
        if (! $base64) {
            return null;
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $ext = match (true) {
            str_starts_with($bytes, "\x89PNG") => 'png',
            str_starts_with($bytes, 'RIFF') => 'webp',
            default => 'jpg',
        };
        $dir = storage_path('app/vision');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir."/msg-{$message->id}.{$ext}";

        try {
            file_put_contents($path, $bytes);

            $out = Claude::run(
                "Leia a imagem em {$path} e descreva o conteúdo em português, de forma objetiva e completa, "
                .'em um parágrafo. É uma imagem enviada por um cliente em uma conversa de WhatsApp. '
                .'Se for um documento (ex.: conta de luz/fatura de energia, boleto, contrato, comprovante), '
                .'extraia os dados principais: emissor/distribuidora, nome do titular, valor total, consumo (kWh), '
                .'mês de referência e datas. Responda SOMENTE com a descrição, sem preâmbulo.',
                90
            );

            $text = trim((string) $out);
            if ($text === '') {
                return null;
            }

            $message->update(['transcript' => $text]);

            return $text;
        } catch (\Throwable $e) {
            Log::warning('Descrição de imagem falhou', ['msg' => $message->id, 'e' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($path);
        }
    }
}
