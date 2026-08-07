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
                    ->orWhereNull('summarized_at');
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

        // ---------- RESUMO (read.ai) ----------
        // Independe da presença confirmada (ver comentário do query acima): se o read.ai
        // mandou o relatório por e-mail, a reunião aconteceu e o resumo vai pra ficha.
        if ($meeting->summarized_at === null) {
            $report = $gmail->findReadAiReport($user, Carbon::parse($meeting->starts_at), Carbon::parse($meeting->ends_at));

            if ($report) {
                $meeting->summary = $report['summary'];
                $meeting->summary_source = 'read.ai';
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
                    $this->appendToNotes($meeting->conversation, $when, $report['summary']);
                }
            } elseif ($endedHoursAgo >= 24) {
                // O relatório não chegou em 24h — para de tentar (resumo fica vazio).
                $meeting->summarized_at = now();
                $meeting->save();
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
    private function appendToNotes(Conversation $conv, string $when, string $summary): void
    {
        $block = "🗓 Reunião {$when} — resumo (read.ai)\n{$summary}";
        $current = trim((string) $conv->notes);
        $conv->notes = $current !== '' ? $block."\n\n".$current : $block;
        $conv->save();
    }
}
