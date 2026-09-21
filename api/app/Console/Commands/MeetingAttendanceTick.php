<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\LeadActivity;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Services\GmailService;
use App\Services\GoogleCalendarService;
use App\Services\StageMover;
use App\Support\MetaConversions;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Pós-reunião: depois que a reunião termina, apura na Meet REST API quem realmente
 * entrou. Se o cliente compareceu (ficou ≥ X min), move o lead para "Reunião Realizada"
 * e puxa o resumo do read.ai (e-mail) para a ficha. Idempotente: cada etapa só roda uma vez
 * (attendance_checked_at / summarized_at servem de trava).
 */
class MeetingAttendanceTick extends Command
{
    protected $signature = 'meetings:attendance-tick {--reapurar=0 : Refaz a presença das reuniões dos últimos N dias que deram "não compareceu" ou ficaram sem medição}';

    protected $description = 'Apura presença no Meet e anexa o resumo do read.ai após a reunião';

    public function handle(GoogleCalendarService $google, GmailService $gmail): int
    {
        $minMinutes = (int) config('services.crm.attendance_min_minutes', 10);
        $doneStage = (string) config('services.crm.stage_meeting_done', 'reuniao-realizada');

        // Reapuração: quando a leitura do Meet estava errada (ex.: só o registro vazio de
        // "batendo na porta" era lido), a reunião fica travada como apurada. Limpar a trava
        // é o que permite medir de novo — sem isso a correção não alcança o que já passou.
        if ($dias = (int) $this->option('reapurar')) {
            $alvo = Meeting::query()
                ->whereNull('cancelled_at')
                ->where('ends_at', '<', now())
                ->where('ends_at', '>', now()->subDays($dias))
                ->where('attended', false)   // quem já constou como presente não se mexe
                ->whereNotNull('attendance_checked_at')
                ->update(['attendance_checked_at' => null, 'attendance_attempts' => 0]);

            $this->info("reapuração: {$alvo} reuniões dos últimos {$dias} dias voltaram para a fila.");
        }

        // Reuniões já encerradas, dos últimos 14 dias, que ainda têm presença a apurar
        // OU resumo a buscar. O resumo NÃO depende mais da presença confirmada: em conta
        // Google pessoal (sem Workspace) a Meet API não devolve presença, então o gate
        // antigo (attended=true) travava o resumo pra sempre. A chegada do relatório do
        // read.ai por e-mail já é a prova de que a reunião aconteceu.
        $meetings = Meeting::query()
            ->whereNull('cancelled_at')   // cancelada: não houve reunião para apurar
            ->where('ends_at', '<', now())
            ->where('ends_at', '>', now()->subDays(14))
            ->where(function ($q) {
                $q->whereNull('attendance_checked_at')
                    ->orWhereNull('summarized_at')
                    // O VÍDEO fica pronto DEPOIS da transcrição — volta por até 2 dias
                    // só para buscar a gravação de quem já tem resumo do Meet.
                    ->orWhere(function ($q) {
                        $q->whereNull('recording_file_id')
                            ->where('summary_source', 'meet')
                            ->where('ends_at', '>', now()->subDays(2));
                    });
            })
            ->with('conversation')
            ->get();

        $tenancy = app(Tenancy::class);

        foreach ($meetings as $meeting) {
            try {
                // Contexto da empresa dona: presença/atividades/tarefas nascem carimbadas
                // e as buscas (conta Google, conversa) ficam isoladas.
                $tenancy->run((int) $meeting->company_id, fn () => $this->process($meeting, $google, $gmail, $minMinutes, $doneStage));
            } catch (\Throwable $e) {
                $this->warn("reunião {$meeting->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function process(Meeting $meeting, GoogleCalendarService $google, GmailService $gmail, int $minMinutes, string $doneStage): void
    {
        $user = $meeting->user_id ? User::find($meeting->user_id) : null;
        if (! $user || ! $user->hasGoogle()) {
            // Fallback: uma conta Google conectada DA MESMA empresa (nunca de outra).
            $user = User::where('company_id', $meeting->company_id)
                ->whereNotNull('google_access_token')->first();
        }
        if (! $user || ! $user->hasGoogle()) {
            $meeting->update(['attendance_checked_at' => now()]); // sem conta Google p/ apurar — encerra

            return;
        }

        $code = $this->meetingCode($meeting->meet_link);
        // abs(): no Carbon 3 o diff é assinado (negativo p/ datas passadas) — sem o abs,
        // os "desistir depois de X horas" (linhas abaixo) nunca disparavam.
        $endedHoursAgo = abs(now()->diffInHours(Carbon::parse($meeting->ends_at)));

        // ---------- PRESENÇA ----------
        if ($meeting->attendance_checked_at === null) {
            if (! $code) {
                $meeting->update(['attendance_checked_at' => now()]); // reunião sem link do Meet — nada a apurar

                return;
            }

            $res = $google->conferenceAttendance(
                $user,
                $code,
                Carbon::parse($meeting->starts_at),
                $meeting->ends_at ? Carbon::parse($meeting->ends_at) : null,
            );

            if (! $res['found']) {
                // Ainda não há registro (reunião recém-acabou) → tenta de novo no próximo tick.
                // Desiste depois de 6h (provavelmente ninguém entrou ou a reunião não ocorreu).
                $meeting->attendance_attempts = ($meeting->attendance_attempts ?? 0) + 1;
                if ($endedHoursAgo >= 6) {
                    $meeting->attendance_checked_at = now();
                }
                $meeting->save();

                return;
            }

            // Conferência existe mas o Google não devolveu NINGUÉM (acontece: só registros de
            // tentativa de entrada, conta pessoal sem os dados). Isso não é falta — é ausência de
            // medição. Marcar "não compareceu" aqui era acusar o cliente com base em nada: fecha a
            // apuração com attended_minutes NULL (o front trata como "sem informação") e deixa o
            // resumo do read.ai, logo abaixo, decidir se a reunião aconteceu.
            $attended = false;
            if (! $res['participants']) {
                $meeting->attendees = [];
                $meeting->attended_minutes = null;
                $meeting->attendance_checked_at = now();
                $meeting->save();
            } else {
                // Tempo do cliente na sala, ignorando bot e equipe (ver Meeting::clientMinutes).
                $clientMinutes = Meeting::clientMinutes($res['participants']);
                $attended = $clientMinutes >= $minMinutes;

                $meeting->attended = $attended;
                $meeting->attended_minutes = $clientMinutes;
                $meeting->attendees = $res['participants'];
                $meeting->attendance_checked_at = now();
                $meeting->save();
            }

            if ($meeting->conversation && $res['participants']) {
                $when = Carbon::parse($meeting->starts_at)->setTimezone(config('app.timezone'))->format('d/m H:i');
                $lista = collect($res['participants'])
                    ->map(fn ($p) => '• '.$p['name'].' ('.$p['minutes'].' min'.($p['bot'] ? ', bot' : '').')')
                    ->implode("\n");

                if ($attended) {
                    StageMover::move($meeting->conversation, $doneStage, $user->id, "Reunião realizada em {$when}");
                    $this->logUmaVez($meeting->conversation->id, "✅ Cliente compareceu à reunião ({$when})", $lista);

                    // DONO DO NEGÓCIO. Sem isto o lead sai da reunião e não pertence a
                    // ninguém: em 18/08/2026, 12 dos 14 negócios em "disse que vai fechar"
                    // estavam sem responsável, e 4 nunca mais receberam uma mensagem.
                    // O anfitrião da reunião é o dono natural — foi ele quem falou com o lead.
                    if (! $meeting->conversation->owner_user_id && $meeting->user_id) {
                        $meeting->conversation->forceFill(['owner_user_id' => $meeting->user_id])->save();
                    }

                    // TAREFA DA PROPOSTA, com prazo. O único negócio fechado no período foi
                    // o que teve proposta na mão do lead no dia seguinte à call; a diferença
                    // entre ele e os outros doze não foi discurso, foi alguém ter feito isto.
                    $tituloProposta = "Enviar proposta — {$meeting->conversation->name} ({$when})";
                    if (! Task::where('conversation_id', $meeting->conversation->id)->where('title', $tituloProposta)->exists()) {
                        Task::create([
                            'conversation_id' => $meeting->conversation->id,
                            'title' => $tituloProposta,
                            'description' => 'Reunião realizada. Enviar proposta/orçamento e combinar o próximo passo com data.',
                            'client' => $meeting->conversation->name,
                            'type' => 'followup',
                            'priority' => 'alta',
                            'column' => 'todo',
                            'position' => (Task::where('column', 'todo')->min('position') ?? 0) - 1,
                            'starts_at' => now()->addMinutes(30),
                            'due' => now()->addDay(),
                        ]);
                    }
                    // Presença CONFIRMADA no Meet — o evento mais valioso do funil para a
                    // Meta otimizar, porque separa quem apareceu de quem só marcou.
                    MetaConversions::enviarUmaVez($meeting->conversation, MetaConversions::REUNIAO_REALIZADA);
                } else {
                    $this->logUmaVez($meeting->conversation->id, "⚠️ Cliente não compareceu à reunião ({$when})", $lista ?: null);
                    // No-show → cria um follow-up (Task) p/ remarcar. O título é fixo por reunião,
                    // então a reapuração (--reapurar) não empilha uma segunda tarefa igual.
                    $titulo = "Remarcar reunião — {$meeting->conversation->name} não compareceu ({$when})";
                    if (! Task::where('conversation_id', $meeting->conversation->id)->where('title', $titulo)->exists()) {
                        Task::create([
                            'conversation_id' => $meeting->conversation->id,
                            'title' => $titulo,
                            'client' => $meeting->conversation->name,
                            'type' => 'followup',
                            'priority' => 'alta',
                            'column' => 'todo',
                            'position' => (Task::where('column', 'todo')->min('position') ?? 0) - 1,
                            'starts_at' => now()->addHour(),
                        ]);
                    }
                }
            }
        }

        // ---------- RESUMO ----------
        // 1ª fonte: transcrição NATIVA do Meet (conta Workspace) resumida pela IA — traz
        // também o link da gravação. 2ª fonte (fallback): e-mail de relatório do read.ai.
        // Independe da presença confirmada (ver comentário do query acima): a existência
        // de transcrição/relatório já prova que a reunião aconteceu.
        if ($meeting->summarized_at === null) {
            $report = null;
            if ($code) {
                $art = $google->conferenceArtifacts($user, $code, Carbon::parse($meeting->starts_at), $meeting->ends_at ? Carbon::parse($meeting->ends_at) : null);
                // Gravação pode existir mesmo sem transcrição — guarda assim que aparecer
                // (é o que liga o player de vídeo na ficha).
                if ($art['recording_file_id'] && ! $meeting->recording_file_id) {
                    $meeting->recording_file_id = $art['recording_file_id'];
                    $meeting->recording_link = $art['recording_link'];
                    $meeting->save();
                }
                if ($art['transcript']) {
                    // Guarda a transcrição BRUTA junto com o resumo. Até 18/08/2026 só o
                    // resumo era salvo, e auditar condução de call exigia rebuscar tudo na
                    // Meet API reunião por reunião — que só funciona enquanto o Google
                    // mantém o registro. O resumo serve para a ficha; a fala literal é o
                    // que responde "o que travou esta venda".
                    if (! $meeting->transcript) {
                        $meeting->forceFill([
                            'transcript' => $art['transcript'],
                            'transcript_source' => 'meet',
                        ])->save();
                    }

                    $resumo = $this->resumirTranscricao($meeting, $art['transcript']);
                    if ($resumo === null) {
                        return; // transcrição existe mas a IA falhou — tenta no próximo tick (desiste em 24h)
                    }
                    if ($art['recording_link']) {
                        $resumo .= "\n\n🎥 Gravação: {$art['recording_link']}";
                    }
                    $report = ['summary' => $resumo, 'source' => 'meet'];
                }
            }
            $report ??= $gmail->findReadAiReport($user, Carbon::parse($meeting->starts_at), Carbon::parse($meeting->ends_at));

            if ($report) {
                $meeting->summary = $report['summary'];
                $meeting->summary_source = $report['source'] ?? 'read.ai';
                $meeting->summarized_at = now();
                // O relatório prova que a reunião OCORREU, não que o cliente entrou. Serve de
                // presença só quando não houve medição nenhuma (conta Google pessoal, em que a
                // Meet API não devolve participantes). Se medimos e o cliente ficou 0 min —
                // reunião em que só a equipe entrou —, a medição vale e o resumo não a apaga.
                if (! $meeting->attended && blank($meeting->attendees)) {
                    $meeting->attended = true;
                }
                $meeting->save();

                if ($meeting->conversation) {
                    $when = Carbon::parse($meeting->starts_at)->setTimezone(config('app.timezone'))->format('d/m/Y H:i');
                    LeadActivity::log($meeting->conversation->id, 'reuniao', "📋 Resumo da reunião ({$when})", $report['summary']);
                    $this->appendToNotes($meeting->conversation, $when, $report['summary'], $meeting->summary_source);
                }
            } elseif ($endedHoursAgo >= 24) {
                // O relatório não chegou em 24h — para de tentar (resumo fica vazio).
                $meeting->summarized_at = now();
                $meeting->save();
            }
        } elseif ($meeting->recording_file_id === null && $meeting->summary_source === 'meet' && $code) {
            // Resumo já saiu mas o VÍDEO ainda não tinha sido publicado no Drive (a gravação
            // fica pronta depois da transcrição): volta só pra buscar a gravação.
            $art = $google->conferenceArtifacts($user, $code, Carbon::parse($meeting->starts_at), $meeting->ends_at ? Carbon::parse($meeting->ends_at) : null, incluirTranscricao: false);
            if ($art['recording_file_id']) {
                $meeting->update([
                    'recording_file_id' => $art['recording_file_id'],
                    'recording_link' => $art['recording_link'],
                ]);
            }
        }
    }

    /**
     * Registra na linha do tempo sem repetir: a reapuração passa de novo pelas mesmas reuniões,
     * e duas linhas iguais ("compareceu"/"não compareceu") só confundem quem lê a ficha.
     */
    private function logUmaVez(int $conversationId, string $title, ?string $body = null): void
    {
        $jaTem = LeadActivity::where('conversation_id', $conversationId)
            ->where('type', 'reuniao')
            ->where('title', $title)
            ->exists();

        if (! $jaTem) {
            LeadActivity::log($conversationId, 'reuniao', $title, $body);
        }
    }

    /** Extrai o código do link do Meet (ex.: https://meet.google.com/abc-defg-hij → abc-defg-hij). */
    private function meetingCode(?string $link): ?string
    {
        if (! $link) {
            return null;
        }
        if (preg_match('#meet\.google\.com/([a-z]{3,4}-[a-z]{3,4}-[a-z]{3,4})#i', $link, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Prepende o resumo no campo Observações da ficha, sem apagar o que já existe. */
    private function appendToNotes(Conversation $conv, string $when, string $summary, ?string $source = null): void
    {
        $label = $source === 'meet' ? 'Meet' : 'read.ai';
        $block = "🗓 Reunião {$when} — resumo ({$label})\n{$summary}";
        $current = trim((string) $conv->notes);
        $conv->notes = $current !== '' ? $block."\n\n".$current : $block;
        $conv->save();
    }

    /**
     * Resume a transcrição da reunião com a IA do painel. Devolve null quando a IA está
     * indisponível/falhou — o chamador NÃO carimba summarized_at, para tentar de novo.
     */
    private function resumirTranscricao(Meeting $meeting, string $transcript): ?string
    {
        if (\App\Support\Claude::indisponivel()) {
            return null;
        }

        // Reunião de 1h rende ~10-15k palavras; corta o miolo se passar do limite do prompt.
        $max = 60000;
        if (mb_strlen($transcript) > $max) {
            $transcript = mb_substr($transcript, 0, (int) ($max * 0.6))
                ."\n[... trecho intermediário omitido ...]\n"
                .mb_substr($transcript, -(int) ($max * 0.4));
        }

        $lead = $meeting->conversation?->name ?: 'o cliente';
        $prompt = <<<PROMPT
Você resume reuniões comerciais para a ficha de um CRM. Abaixo está a transcrição de uma reunião entre a equipe Bancalize e o lead "{$lead}" ({$meeting->title}).

Escreva em português, SEM markdown, exatamente neste formato:
- 1º parágrafo: visão geral em 2 a 4 frases (quem é o lead, o que ele quer, como a conversa terminou).
- Depois uma linha "Pontos discutidos:" seguida de bullets "•" (no máximo 10) com o que foi tratado.
- Depois uma linha "Próximos passos:" com bullets "•" dos compromissos combinados (quem faz o quê e prazo, se dito).

Não invente nada que não esteja na transcrição. Responda SÓ com o resumo.

Transcrição:
{$transcript}
PROMPT;

        $out = \App\Support\Claude::run($prompt, 180);
        $out = trim((string) $out);

        return $out !== '' ? $out : null;
    }
}
