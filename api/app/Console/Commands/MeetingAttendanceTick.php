<?php

namespace App\Console\Commands;

use App\Models\LeadActivity;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GmailService;
use App\Services\GoogleCalendarService;
use App\Services\StageMover;
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
    protected $signature = 'meetings:attendance-tick';

    protected $description = 'Apura presença no Meet e anexa o resumo do read.ai após a reunião';

    public function handle(GoogleCalendarService $google, GmailService $gmail): int
    {
        $minMinutes = (int) config('services.crm.attendance_min_minutes', 10);
        $doneStage = (string) config('services.crm.stage_meeting_done', 'reuniao-realizada');

        // Reuniões já encerradas, dos últimos 14 dias, que ainda têm presença a apurar
        // OU resumo a buscar. O resumo NÃO depende mais da presença confirmada: em conta
        // Google pessoal (sem Workspace) a Meet API não devolve presença, então o gate
        // antigo (attended=true) travava o resumo pra sempre. A chegada do relatório do
        // read.ai por e-mail já é a prova de que a reunião aconteceu.
        $meetings = Meeting::query()
            ->where('ends_at', '<', now())
            ->where('ends_at', '>', now()->subDays(14))
            ->where(function ($q) {
                $q->whereNull('attendance_checked_at')
                    ->orWhereNull('summarized_at');
            })
            ->with('conversation')
            ->get();

        foreach ($meetings as $meeting) {
            try {
                $this->process($meeting, $google, $gmail, $minMinutes, $doneStage);
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
            $user = User::whereNotNull('google_access_token')->first(); // fallback: a conta conectada
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

            $res = $google->conferenceAttendance($user, $code, Carbon::parse($meeting->starts_at));

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

            // Humanos (sem o bot de anotação) ordenados por tempo em sala. O host costuma ser o
            // que mais fica; o 2º maior é o cliente — exigimos ≥ X min dele para contar presença.
            $humans = array_values(array_filter($res['participants'], fn ($p) => ! $p['bot']));
            usort($humans, fn ($a, $b) => $b['minutes'] <=> $a['minutes']);
            $clientMinutes = count($humans) >= 2 ? (int) $humans[1]['minutes'] : 0;
            $attended = $clientMinutes >= $minMinutes;

            $meeting->attended = $attended;
            $meeting->attended_minutes = $clientMinutes;
            $meeting->attendees = $res['participants'];
            $meeting->attendance_checked_at = now();
            $meeting->save();

            if ($meeting->conversation) {
                $when = Carbon::parse($meeting->starts_at)->setTimezone(config('app.timezone'))->format('d/m H:i');
                $lista = collect($res['participants'])
                    ->map(fn ($p) => '• '.$p['name'].' ('.$p['minutes'].' min'.($p['bot'] ? ', bot' : '').')')
                    ->implode("\n");

                if ($attended) {
                    StageMover::move($meeting->conversation, $doneStage, $user->id, "Reunião realizada em {$when}");
                    LeadActivity::log($meeting->conversation->id, 'reuniao', "✅ Cliente compareceu à reunião ({$when})", $lista);
                } else {
                    LeadActivity::log($meeting->conversation->id, 'reuniao', "⚠️ Cliente não compareceu à reunião ({$when})", $lista ?: null);
                    // No-show → cria um follow-up (Task) p/ remarcar. Roda uma vez só (este bloco
                    // de apuração só executa enquanto attendance_checked_at era null).
                    \App\Models\Task::create([
                        'conversation_id' => $meeting->conversation->id,
                        'title' => "Remarcar reunião — {$meeting->conversation->name} não compareceu ({$when})",
                        'client' => $meeting->conversation->name,
                        'type' => 'followup',
                        'priority' => 'alta',
                        'column' => 'todo',
                        'position' => (\App\Models\Task::where('column', 'todo')->min('position') ?? 0) - 1,
                        'starts_at' => now()->addHour(),
                    ]);
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
                // O relatório prova que a reunião ocorreu — marca presença mesmo sem a Meet API.
                if (! $meeting->attended) {
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
    private function appendToNotes(\App\Models\Conversation $conv, string $when, string $summary): void
    {
        $block = "🗓 Reunião {$when} — resumo (read.ai)\n{$summary}";
        $current = trim((string) $conv->notes);
        $conv->notes = $current !== '' ? $block."\n\n".$current : $block;
        $conv->save();
    }
}
