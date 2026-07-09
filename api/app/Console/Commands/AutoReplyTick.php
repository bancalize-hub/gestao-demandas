<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\User;
use App\Services\AiReplyService;
use App\Services\MeetingScheduler;
use App\Services\TranscriptionService;
use App\Support\Evolution;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Atendimento automático: para cada conversa com auto_reply ligado e uma resposta
 * pendente (auto_reply_due_at vencido), a IA gera e ENVIA a resposta ao lead sozinha.
 * Quando o lead confirma um horário, também AGENDA a reunião (Google + Meet) sozinha.
 */
class AutoReplyTick extends Command
{
    protected $signature = 'auto-reply:tick';

    protected $description = 'Responde (e agenda) automaticamente os leads das conversas com atendimento automático ligado';

    public function handle(AiReplyService $ai, MeetingScheduler $scheduler, TranscriptionService $stt): int
    {
        $tenancy = app(Tenancy::class);

        // Consulta global (sem tenant vinculado): conversas vencidas de TODAS as empresas.
        $due = Conversation::where('auto_reply', true)
            ->whereNotNull('auto_reply_due_at')
            ->where('auto_reply_due_at', '<=', now())
            ->get();

        foreach ($due as $conv) {
            // Daqui pra frente, tudo roda no contexto da empresa dona da conversa: as mensagens
            // criadas nascem com o company_id certo e os envios usam o WhatsApp (instância) dela.
            $tenancy->set($conv->company_id);

            try {
            // Dono da agenda Google DESTA empresa (conta usada para marcar as reuniões).
            $googleUser = User::where('company_id', $conv->company_id)
                ->whereNotNull('google_refresh_token')->first();

            // Última mensagem é nossa? Então já foi respondida (humano assumiu) → limpa e segue.
            $last = $conv->messages()->reorder()->orderByDesc('ts')->orderByDesc('id')->first();
            if (! $last || $last->is_out) {
                $conv->update(['auto_reply_due_at' => null]);

                continue;
            }

            // O cliente mandou áudio? Transcreve ANTES de responder (a IA precisa "ouvir").
            // Transcreve os áudios recentes ainda sem transcrição e, se o último é um áudio
            // que ainda dá pra tentar, espera o próximo tick em vez de responder no escuro.
            if ($stt->enabled()) {
                $pendingVoice = $conv->messages()
                    ->where('type', 'voice')->where('is_out', false)
                    ->whereNull('transcript')->where('transcribe_attempts', '<', 5)
                    ->whereNotNull('wa_id')
                    ->reorder()->orderByDesc('ts')->orderByDesc('id')->take(5)->get();
                foreach ($pendingVoice as $v) {
                    $stt->transcribe($v);
                }

                if ($last->type === 'voice') {
                    $fresh = $last->fresh();
                    if (($fresh->transcript ?? null) === null && (int) $fresh->transcribe_attempts < 5) {
                        $conv->update(['auto_reply_due_at' => now()->addSeconds(30)]); // ainda transcrevendo
                        $this->info("auto-reply: aguardando transcrição do áudio (conversa {$conv->id})");

                        continue;
                    }
                }
            }

            $reply = null;

            // Se a conversa tem pinta de agendamento, tenta marcar sozinho (cria evento + Meet).
            // Não tenta de novo se já enviamos um link de reunião (evita marcar duas vezes).
            if ($googleUser && $conv->phone && $this->looksLikeScheduling($conv) && ! $this->alreadyBooked($conv)) {
                try {
                    $res = $scheduler->decideAndBook($googleUser, $conv, $ai->memoryContext($conv));
                    // Usa a mensagem do agendador SEMPRE que ele rodou: se marcou, é a confirmação com
                    // link do Meet; se não marcou (horário ocupado/dia inválido), é a proposta de horários.
                    // Assim a IA nunca diz "confirmado" sem o evento ter sido realmente criado.
                    if (! empty($res['message'])) {
                        $reply = $res['message'];
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('auto-reply: agendamento falhou', ['conv' => $conv->id, 'e' => $e->getMessage()]);
                }
            }

            // Não agendou → resposta de texto normal (mesmo estilo/regras da sugestão).
            if ($reply === null) {
                $reply = $ai->generate($conv);
            }

            if ($reply === null || trim($reply) === '') {
                $conv->update(['auto_reply_due_at' => now()->addMinutes(2)]);
                $this->warn("auto-reply: falha ao gerar para conversa {$conv->id}");

                continue;
            }

            $waId = $conv->phone ? Evolution::sendText($conv->phone, $reply) : null;
            if ($waId === null) {
                $conv->update(['auto_reply_due_at' => now()->addMinutes(2)]);
                $this->warn("auto-reply: falha ao enviar para conversa {$conv->id}");

                continue;
            }

            $ts = time();
            $data = [
                'type' => 'text',
                'is_out' => true,
                'text' => mb_substr($reply, 0, 4000),
                'time' => date('H:i', $ts),
                'ts' => $ts,
                'position' => ((int) $conv->messages()->max('position')) + 1,
            ];
            // Já grava com o wa_id do envio → o eco do webhook é ignorado (não duplica).
            // updateOrCreate por wa_id fecha a corrida caso o eco tenha chegado primeiro.
            if ($waId !== '') {
                $conv->messages()->updateOrCreate(['wa_id' => $waId], $data);
            } else {
                $conv->messages()->create($data);
            }

            $conv->update([
                'preview' => mb_substr($reply, 0, 80),
                'time' => date('H:i', $ts),
                'last_message_at' => now(),
                'auto_reply_due_at' => null,
            ]);

            $this->info("auto-reply: respondeu conversa {$conv->id} ({$conv->name})");
            } finally {
                $tenancy->forget();
            }
        }

        return self::SUCCESS;
    }

    /** Heurística barata: as últimas mensagens falam de horário/agendamento? Evita chamada extra à IA. */
    private function looksLikeScheduling(Conversation $conv): bool
    {
        $recent = $conv->messages()
            ->where('type', 'text')->whereNotNull('text')
            ->reorder()->orderByDesc('ts')->orderByDesc('id')->take(4)
            ->pluck('text')->implode(' ');

        return (bool) preg_match('/\d{1,2}\s*h\b|\d{1,2}:\d{2}|amanh[ãa]|hoje|segunda|ter[çc]a|quarta|quinta|sexta|s[áa]bado|reuni|marcar|agend|hor[áa]rio|pode ser|confirm|fechado|call|meet/iu', $recent);
    }

    /** Já existe uma reunião marcada nesta conversa? (enviamos um link do Meet recentemente.) */
    private function alreadyBooked(Conversation $conv): bool
    {
        return $conv->messages()
            ->where('is_out', true)->where('text', 'like', '%meet.google.com%')
            ->exists();
    }
}
