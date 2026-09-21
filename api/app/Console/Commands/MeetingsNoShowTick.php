<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Support\Evolution;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Avisa quando o LEAD está na sala e ninguém da nossa equipe entrou.
 *
 * Em 12/08/2026 um lead que já tinha dito que ia fechar ficou sozinho na sala duas
 * reuniões seguidas ("não tem ninguém na sala", "é a segunda vez seguida de imprevistos").
 * O tick de presença existente só descobre isso DEPOIS que a reunião acaba, quando o
 * estrago está feito. Este roda durante a reunião, enquanto ainda dá para salvar.
 *
 * A regra é estreita de propósito: sala vazia dos dois lados não é falta nossa (o lead
 * também não veio), então só alerta quando há alguém lá dentro e nenhum nome do time.
 */
class MeetingsNoShowTick extends Command
{
    protected $signature = 'meetings:noshow-tick
        {--depois=4 : minutos após o início a partir dos quais a ausência conta}
        {--ate=20 : minutos após o início até quando vale alertar}';

    protected $description = 'Alerta a equipe quando o cliente está na sala e ninguém nosso entrou';

    public function handle(GoogleCalendarService $google): int
    {
        $depois = max(1, (int) $this->option('depois'));
        $ate = max($depois + 1, (int) $this->option('ate'));
        $tenancy = app(Tenancy::class);

        $emAndamento = Meeting::withoutGlobalScopes()
            ->whereNull('cancelled_at')
            ->whereNotNull('meet_link')
            ->whereNotNull('user_id')
            ->whereBetween('starts_at', [now()->subMinutes($ate), now()->subMinutes($depois)])
            ->get();

        foreach ($emAndamento as $m) {
            $tenancy->set($m->company_id);

            if (! preg_match('~([a-z]{3}-[a-z]{4}-[a-z]{3})~', (string) $m->meet_link, $mm)) {
                continue;
            }
            $host = User::find($m->user_id);
            if (! $host?->google_refresh_token) {
                continue;
            }

            try {
                $res = $google->conferenceAttendance($host, $mm[1], Carbon::parse($m->starts_at), now());
            } catch (\Throwable $e) {
                continue;
            }

            $participantes = collect($res['participants'] ?? []);
            if ($participantes->isEmpty()) {
                continue; // ninguém entrou ainda — não é falta nossa
            }

            // O time é reconhecido por nome de exibição: a Meet API não devolve e-mail.
            // Nome novo na equipe precisa entrar em CRM_TEAM_DISPLAY_NAMES, senão o alerta
            // dispara com a pessoa dentro da sala (mesma armadilha da apuração de presença).
            $timeNomes = collect((array) config('services.crm.team_display_names', []))
                ->map(fn ($n) => mb_strtolower(trim((string) $n)))->filter();

            $temAlguemNosso = $participantes->contains(function ($p) use ($timeNomes) {
                $nome = mb_strtolower((string) ($p['name'] ?? ''));

                return $timeNomes->contains(fn ($t) => $t !== '' && str_contains($nome, $t));
            });

            if ($temAlguemNosso) {
                continue;
            }

            $quem = $participantes->pluck('name')->implode(', ');
            $titulo = "🔴 Cliente sozinho na sala — {$m->title}";

            if (Task::where('title', $titulo)->where('column', '!=', 'done')->exists()) {
                continue;
            }

            Task::create([
                'conversation_id' => $m->conversation_id,
                'title' => $titulo,
                'description' => "A reunião começou às ".Carbon::parse($m->starts_at)->format('H:i')
                    ." e {$quem} está esperando. Ninguém da equipe entrou. Entre agora ou avise o cliente pelo WhatsApp.",
                'client' => $m->conversation?->name,
                'type' => 'followup',
                'priority' => 'alta',
                'column' => 'todo',
                'position' => (Task::where('column', 'todo')->min('position') ?? 0) - 1,
                'starts_at' => now(),
                'due' => now(),
            ]);

            Evolution::log('meeting.noshow_equipe', [
                'meeting_id' => $m->id, 'na_sala' => $quem, 'host' => $host->name,
            ], 'warning');

            $this->warn("Reunião {$m->id}: {$quem} na sala, ninguém da equipe.");
        }

        return self::SUCCESS;
    }
}
