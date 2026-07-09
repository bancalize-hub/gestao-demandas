<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\TranscriptionService;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Transcreve os áudios (voice) recebidos que ainda não têm transcrição. Roda a cada minuto.
 * Assim a transcrição aparece no chat (humano lê) e fica disponível p/ a IA responder.
 * O atendimento automático também transcreve sob demanda (AutoReplyTick) p/ responder na hora.
 */
class VoiceTranscribeTick extends Command
{
    protected $signature = 'voice:transcribe-tick';

    protected $description = 'Transcreve áudios recebidos do WhatsApp (Groq Whisper)';

    public function handle(TranscriptionService $stt): int
    {
        if (! $stt->enabled()) {
            return self::SUCCESS; // sem chave Groq — nada a fazer
        }

        $pending = Message::query()
            ->where('type', 'voice')
            ->where('is_out', false)
            ->whereNull('transcript')
            ->where('transcribe_attempts', '<', 5)
            ->whereNotNull('wa_id')
            // Filtra pela DATA REAL do áudio (ts), não created_at (que é a data de importação).
            // Áudio antigo já não tem mídia no Evolution — não adianta tentar.
            ->where('ts', '>', now()->subDays(2)->timestamp)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $tenancy = app(Tenancy::class);

        foreach ($pending as $msg) {
            // Busca a mídia no WhatsApp da empresa dona da mensagem (instância correta).
            $text = $tenancy->run((int) $msg->company_id, fn () => $stt->transcribe($msg));
            if ($text !== null) {
                $this->info("transcrito msg {$msg->id}: ".mb_substr($text, 0, 50));
            }
        }

        return self::SUCCESS;
    }
}
