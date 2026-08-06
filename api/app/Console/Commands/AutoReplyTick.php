<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\User;
use App\Services\AiReplyService;
use App\Services\ChatSender;
use App\Services\MeetingScheduler;
use App\Services\TranscriptionService;
use App\Support\Claude;
use App\Support\Evolution;
use App\Support\Tenancy;
use App\Support\Wa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Atendimento automático: para cada conversa com auto_reply ligado e uma resposta
 * pendente (auto_reply_due_at vencido), a IA gera e ENVIA a resposta ao lead sozinha.
 * Quando o lead confirma um horário, também AGENDA a reunião (Google + Meet) sozinha.
 */
class AutoReplyTick extends Command
{
    protected $signature = 'auto-reply:tick';

    protected $description = 'Responde (e agenda) automaticamente os leads das conversas com atendimento automático ligado';

    public function handle(AiReplyService $ai, MeetingScheduler $scheduler, TranscriptionService $stt, ChatSender $sender): int
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
                // Sem telefone (ex.: conversa @lid órfã) não há como enviar — desiste de vez,
                // senão a pendência fica sendo reagendada para sempre.
                if (! $conv->phone) {
                    $conv->update(['auto_reply_due_at' => null]);
                    $this->warn("auto-reply: conversa {$conv->id} sem telefone — pendência cancelada");

                    continue;
                }
                // Dono da agenda Google DESTA empresa (conta usada para marcar as reuniões).
                $googleUser = User::where('company_id', $conv->company_id)
                    ->whereNotNull('google_refresh_token')->first();

                // Resposta automática MUITO atrasada (IA fora do ar, número desconectado):
                // responder o lead horas depois é pior que não responder — ele volta para
                // a fila humana, com registro do que aconteceu.
                $ultimaEntrada = $conv->lastInboundTs();
                $limite = (int) config('services.auto_reply.stale_hours', 6) * 3600;
                if ($ultimaEntrada && (time() - $ultimaEntrada) > $limite) {
                    $conv->update(['auto_reply_due_at' => null]);
                    Evolution::log('auto_reply.pendencia_vencida', [
                        'conversation_id' => $conv->id,
                        'horas' => round((time() - $ultimaEntrada) / 3600, 1),
                    ], 'warning');
                    $this->warn("auto-reply: pendência vencida na conversa {$conv->id} — atendimento humano");

                    continue;
                }

                // IA fora do ar (ex.: token revogado): não adianta rodar as 30 conversas
                // pendentes a cada 2 min. Espera a trégua do circuit breaker.
                if ($motivo = Claude::indisponivel()) {
                    $conv->update(['auto_reply_due_at' => now()->addMinutes(10)]);
                    Evolution::log('auto_reply.ia_fora_do_ar', [
                        'conversation_id' => $conv->id,
                        'motivo' => $motivo,
                    ], 'error');

                    continue;
                }

                // API oficial fora da janela de 24h: a Meta recusa texto livre. Sem esta
                // guarda o tick gerava resposta com a IA (caro) e reagendava a cada 2 min
                // para sempre, sem nada nunca sair. Cancela a pendência e registra.
                if (! Wa::forConversation($conv)->canSendFreeform($conv->lastInboundTs())) {
                    $conv->update(['auto_reply_due_at' => null]);
                    Evolution::log('auto_reply.janela_fechada', [
                        'conversation_id' => $conv->id,
                        'wa_account_id' => $conv->wa_account_id,
                    ], 'warning');
                    $this->warn("auto-reply: janela de 24h fechada na conversa {$conv->id} — só template aprovado");

                    continue;
                }

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

                // O cliente mandou imagem? Descreve ANTES de responder (a IA precisa "ver" —
                // ex.: foto da conta de luz). Síncrono: a descrição fica pronta neste tick mesmo.
                if ($stt->visionEnabled()) {
                    $pendingImages = $conv->messages()
                        ->where('type', 'image')->where('is_out', false)
                        ->whereNull('transcript')->where('transcribe_attempts', '<', 3)
                        ->whereNotNull('wa_id')
                        ->reorder()->orderByDesc('ts')->orderByDesc('id')->take(3)->get();
                    foreach ($pendingImages as $img) {
                        $stt->describeImage($img);
                    }
                }

                $reply = null;

                // Se a conversa tem pinta de agendamento, entrega ao agendador (marcar, remarcar ou
                // cancelar). Ele é quem faz valer a regra de ouro — um agendamento ativo por contato —
                // então não bloqueamos mais quando já existe reunião: é justamente aí que mora a
                // remarcação. Com reunião ativa, só chamamos quando o lead fala em mudar/desmarcar.
                $active = Meeting::activeFor($conv->id);
                $agendar = $googleUser && $conv->phone && $this->looksLikeScheduling($conv)
                    && (! $active || $this->looksLikeReschedule($conv));

                if ($agendar) {
                    try {
                        $res = $scheduler->decideAndBook($googleUser, $conv, $ai->memoryContext($conv));
                        // Usa a mensagem do agendador SEMPRE que ele agiu (marcou/remarcou/cancelou/
                        // perguntou) e também quando ainda não há reunião (aí é a proposta de horários).
                        // Assim a IA nunca diz "confirmado" sem o evento ter sido realmente criado — e,
                        // com reunião já marcada, um "action: nada" cai na resposta normal da IA.
                        $agiu = ($res['action'] ?? 'nada') !== 'nada';
                        if (! empty($res['message']) && ($agiu || ! $active)) {
                            $reply = $res['message'];
                        }
                    } catch (\Throwable $e) {
                        Log::warning('auto-reply: agendamento falhou', ['conv' => $conv->id, 'e' => $e->getMessage()]);
                    }
                }

                // Não agendou → resposta de texto normal (mesmo estilo/regras da sugestão).
                if ($reply === null) {
                    $reply = $ai->generate($conv);

                    // A resposta de texto não marca nada: se ela promete reunião ("está confirmada",
                    // "o convite vai chegar") sem existir evento, o lead fica com uma reunião que só
                    // existe no papo. Não dá para desdizer sozinho, mas fica registrado para revisão.
                    if ($reply && ! $active
                        && preg_match('/reuni[ãa]o (est[áa] )?(confirmad|marcad|agendad)|convite.*(chegar|enviad)|'
                            .'j[áa] (marquei|agendei|deixei marcad)/iu', $reply)) {
                        Evolution::log('auto_reply.confirmou_sem_agendar', [
                            'conversation_id' => $conv->id,
                            'trecho' => mb_substr($reply, 0, 200),
                        ], 'error');
                    }
                }

                if ($reply === null || trim($reply) === '') {
                    $conv->update(['auto_reply_due_at' => now()->addMinutes(2)]);
                    Evolution::log('auto_reply.ia_nao_gerou', [
                        'conversation_id' => $conv->id,
                        'ia_fora' => Claude::indisponivel(),
                    ], 'error');
                    $this->warn("auto-reply: falha ao gerar para conversa {$conv->id}");

                    continue;
                }

                // A IA pode anexar um material (PDF etc.) marcando [MATERIAL: #id] no fim.
                [$reply, $material] = AiReplyService::extrairMaterial($reply);
                if (trim($reply) === '') {
                    $reply = 'Segue o material.';
                }

                $msg = $sender->text($conv, $reply);
                if (! $msg) {
                    $conv->update(['auto_reply_due_at' => now()->addMinutes(2)]);
                    Evolution::log('auto_reply.envio_falhou', [
                        'conversation_id' => $conv->id,
                        'wa_account_id' => $conv->wa_account_id,
                        'provider' => $conv->account?->provider,
                    ], 'error');
                    $this->warn("auto-reply: falha ao enviar para conversa {$conv->id}");

                    continue;
                }

                // O lead voltou a ser atendido: a pendência morre e a rodada de retomada
                // ativa zera (se ele sumir de novo, a contagem recomeça do começo).
                $conv->update(['auto_reply_due_at' => null, 'nudge_count' => 0, 'nudge_last_at' => null]);

                if ($material) {
                    $sender->material($conv, $material);
                }

                $this->info("auto-reply: respondeu conversa {$conv->id} ({$conv->name})");
            } catch (\Throwable $e) {
                // Uma conversa problemática NUNCA derruba o tick (e as demais pendências):
                // registra, reagenda e segue. (Ex.: prompt gigante matava o tick inteiro
                // e ninguém mais era respondido.)
                Log::error('auto-reply: falha na conversa', ['conv' => $conv->id, 'e' => $e->getMessage()]);
                $conv->update(['auto_reply_due_at' => now()->addMinutes(5)]);
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

    /**
     * O lead está pedindo para mexer numa reunião já marcada? Filtro barato antes de gastar uma
     * chamada de IA: pega os pedidos explícitos ("preciso adiar", "cancelar") e também o sinal
     * decisivo da remarcação — ele citar um dia/horário, mesmo sem usar a palavra "remarcar".
     * Só olha o que o LEAD escreveu/falou (as nossas mensagens sempre citam data e horário).
     */
    private function looksLikeReschedule(Conversation $conv): bool
    {
        $recent = $conv->messages()
            ->where('is_out', false)
            ->where(fn ($q) => $q->where('type', 'text')->whereNotNull('text')
                ->orWhere(fn ($v) => $v->where('type', 'voice')->whereNotNull('transcript')))
            ->reorder()->orderByDesc('ts')->orderByDesc('id')->take(4)
            ->get(['type', 'text', 'transcript'])
            ->map(fn ($m) => $m->type === 'voice' ? (string) $m->transcript : (string) $m->text)
            ->implode(' ');

        return (bool) preg_match(
            '/remarc|reagend|desmarc|cancel|adia[rn]|antecip|transferir|passar para|semana que vem|'
            .'mudar|trocar|outro (dia|hor[áa]rio)|n[ãa]o (vou )?consig|n[ãa]o vou conseguir|'
            .'n[ãa]o vai dar|imprevisto|melhor n[ao]\b|'
            .'\d{1,2}\s*h\b|\d{1,2}:\d{2}|amanh[ãa]|segunda|ter[çc]a|quarta|quinta|sexta/iu',
            $recent,
        );
    }
}
