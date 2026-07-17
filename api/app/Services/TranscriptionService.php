<?php

namespace App\Services;

use App\Models\Message;
use App\Support\Evolution;
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

        $base64 = Evolution::mediaBase64($message->wa_id);
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
            \App\Support\Realtime::messagePatched($message, ['transcript' => $text]);

            return $text;
        } catch (\Throwable $e) {
            Log::warning('Groq transcrição exceção', ['e' => $e->getMessage()]);

            return null;
        }
    }
}
