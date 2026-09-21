<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Guarda a transcrição BRUTA das reuniões (falas "Fulano: texto") em `meetings.transcript`.
 *
 * O tick pós-reunião já lê a transcrição da Meet API, mas só guarda o RESUMO — bom para a
 * ficha do lead, inútil para auditar condução de call: erro de venda aparece na fala
 * literal (objeção sem resposta, preço solto no fim, call que acaba sem próximo passo).
 * Aqui o texto é buscado na mesma fonte e persistido inteiro.
 *
 * Só funciona em reunião hospedada por conta Workspace (contato@/maysa@) — Gmail pessoal
 * nunca gravou nem transcreveu, então reunião anterior a 10/08/2026 não tem o que buscar.
 */
class MeetingsTranscrever extends Command
{
    protected $signature = 'meetings:transcrever
        {--desde=2026-08-01 : só reuniões a partir desta data}
        {--id= : transcreve só esta reunião}
        {--limite=0 : para depois de N reuniões (0 = todas)}
        {--forcar : rebusca mesmo quem já tem transcrição}';

    protected $description = 'Baixa e guarda a transcrição bruta das reuniões (Meet API)';

    public function handle(GoogleCalendarService $google): int
    {
        $q = Meeting::withoutGlobalScopes()
            ->whereNotNull('meet_link')
            ->whereNotNull('user_id')
            ->where('starts_at', '>=', $this->option('desde'))
            ->where('starts_at', '<', now())
            ->orderByDesc('starts_at');

        if ($id = $this->option('id')) {
            $q->where('id', (int) $id);
        }
        if (! $this->option('forcar')) {
            $q->whereNull('transcript');
        }

        $reunioes = $q->get();
        $this->info($reunioes->count().' reunião(ões) para transcrever.');

        $ok = 0;
        $vazias = 0;
        $limite = (int) $this->option('limite');

        foreach ($reunioes as $m) {
            if ($limite > 0 && $ok >= $limite) {
                break;
            }

            $host = User::find($m->user_id);
            if (! $host?->google_refresh_token) {
                $this->line(sprintf('  <fg=gray>#%-4d %-34s anfitrião sem Google conectado</>', $m->id, $this->corta($m->title)));

                continue;
            }
            if (! preg_match('~([a-z]{3}-[a-z]{4}-[a-z]{3})~', (string) $m->meet_link, $mm)) {
                $this->line(sprintf('  <fg=gray>#%-4d %-34s link do Meet sem código</>', $m->id, $this->corta($m->title)));

                continue;
            }

            // O company_id da reunião manda: os artefatos saem do Drive/Meet do anfitrião dela.
            app(Tenancy::class)->set($m->company_id);

            try {
                $art = $google->conferenceArtifacts($host, $mm[1], $m->starts_at, $m->ends_at);
            } catch (\Throwable $e) {
                $this->error(sprintf('  #%-4d %-34s %s', $m->id, $this->corta($m->title), mb_substr($e->getMessage(), 0, 60)));

                continue;
            }

            $texto = (string) ($art['transcript'] ?? '');
            if (trim($texto) === '') {
                $vazias++;
                $this->line(sprintf('  <fg=yellow>#%-4d %-34s sem transcrição na Meet API</>', $m->id, $this->corta($m->title)));

                continue;
            }

            $m->forceFill([
                'transcript' => $texto,
                'transcript_source' => 'meet',
                // A gravação costuma ficar pronta depois; se veio junto, aproveita.
                'recording_file_id' => $m->recording_file_id ?: ($art['recording_file_id'] ?? null),
                'recording_link' => $m->recording_link ?: ($art['recording_link'] ?? null),
            ])->save();

            $ok++;
            $this->line(sprintf('  <fg=green>#%-4d %-34s %s caracteres · %d falas</>',
                $m->id, $this->corta($m->title), number_format(mb_strlen($texto)), substr_count($texto, "\n") + 1));
        }

        $this->newLine();
        $this->info("Transcritas: {$ok} · sem material na Meet API: {$vazias}");

        return self::SUCCESS;
    }

    private function corta(?string $t): string
    {
        $t = trim((string) $t);

        return mb_strlen($t) > 34 ? mb_substr($t, 0, 33).'…' : $t;
    }
}
