<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Teste da Meet REST API: lista as reuniões recentes do Google Meet do usuário,
 * mostrando quem realmente entrou (participantes + horários) e se há
 * transcrição / resumo do Gemini (smart notes) / gravação disponíveis.
 *
 * Uso: php artisan meet:test --user=1 --days=30
 */
class MeetTest extends Command
{
    protected $signature = 'meet:test {--user=1} {--days=30}';

    protected $description = 'Lista reuniões recentes do Meet com participantes, transcrição, resumo e gravação';

    public function handle(GoogleCalendarService $google): int
    {
        $user = User::find((int) $this->option('user'));
        if (! $user || ! $user->hasGoogle()) {
            $this->error('Usuário sem conta Google vinculada.');

            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        $since = Carbon::now()->subDays($days)->startOfDay()->toRfc3339String();

        try {
            $meet = $google->meet($user);

            $resp = $meet->conferenceRecords->listConferenceRecords([
                'filter' => 'start_time>="'.$since.'"',
                'pageSize' => 50,
            ]);
        } catch (\Throwable $e) {
            $this->error('Falha ao chamar a Meet API: '.$e->getMessage());
            $this->line('Se for erro de escopo/403, reconecte o Google na página /agenda (o escopo do Meet é novo).');

            return self::FAILURE;
        }

        $records = $resp->getConferenceRecords() ?? [];
        $this->info("Reuniões do Meet nos últimos {$days} dias: ".count($records));

        foreach ($records as $rec) {
            $this->line('');
            $this->line(str_repeat('─', 60));
            $start = $rec->getStartTime() ? Carbon::parse($rec->getStartTime())->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') : '?';
            $end = $rec->getEndTime() ? Carbon::parse($rec->getEndTime())->setTimezone('America/Sao_Paulo')->format('H:i') : 'em andamento';
            $this->line("📅 {$start} → {$end}   (space: {$rec->getSpace()})");

            // ---- Participantes (quem ENTROU de fato) ----
            $parts = $meet->conferenceRecords_participants->listConferenceRecordsParticipants($rec->getName())->getParticipants() ?? [];
            $this->line('👥 Participantes ('.count($parts).'):');
            foreach ($parts as $p) {
                $who = $p->getSignedinUser()?->getDisplayName()
                    ?: $p->getAnonymousUser()?->getDisplayName()
                    ?: ($p->getPhoneUser() ? 'Telefone' : 'Desconhecido');
                $in = $p->getEarliestStartTime() ? Carbon::parse($p->getEarliestStartTime())->setTimezone('America/Sao_Paulo')->format('H:i') : '?';
                $out = $p->getLatestEndTime() ? Carbon::parse($p->getLatestEndTime())->setTimezone('America/Sao_Paulo')->format('H:i') : 'ainda na sala';
                $this->line("   • {$who}  (entrou {$in} · saiu {$out})");
            }

            // ---- Transcrição ----
            $trs = $meet->conferenceRecords_transcripts->listConferenceRecordsTranscripts($rec->getName())->getTranscripts() ?? [];
            foreach ($trs as $t) {
                $doc = $t->getDocsDestination();
                $link = $doc ? ($doc->getExportUri() ?: ('doc '.$doc->getDocument())) : '(sem doc)';
                $this->line("📝 Transcrição [{$t->getState()}]: {$link}");
            }

            // ---- Resumo do Gemini (smart notes) ----
            $sns = $meet->conferenceRecords_smartNotes->listConferenceRecordsSmartNotes($rec->getName())->getSmartNotes() ?? [];
            foreach ($sns as $s) {
                $doc = $s->getDocsDestination();
                $link = $doc ? ($doc->getExportUri() ?: ('doc '.$doc->getDocument())) : '(sem doc)';
                $this->line("✨ Resumo Gemini [{$s->getState()}]: {$link}");
            }

            // ---- Gravação ----
            $recs = $meet->conferenceRecords_recordings->listConferenceRecordsRecordings($rec->getName())->getRecordings() ?? [];
            foreach ($recs as $r) {
                $dst = $r->getDriveDestination();
                $link = $dst ? ($dst->getExportUri() ?: ('file '.$dst->getFile())) : '(sem arquivo)';
                $this->line("🎥 Gravação [{$r->getState()}]: {$link}");
            }

            if (! $trs && ! $sns && ! $recs) {
                $this->line('   (sem transcrição/resumo/gravação — não foram ativados nessa reunião)');
            }
        }

        return self::SUCCESS;
    }
}
