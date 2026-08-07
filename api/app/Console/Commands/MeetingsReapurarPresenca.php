<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use Illuminate\Console\Command;

/**
 * Reapura presença das reuniões já medidas, aplicando a regra nova de cliente.
 *
 * Existe porque a regra antiga ("o 2º humano que mais ficou é o cliente") gravava
 * comparecimento quando só a equipe entrava na sala. Os participantes ficaram salvos em
 * `meetings.attendees`, então dá para recalcular sem chamar a API do Meet de novo.
 *
 * O que este comando NÃO faz, de propósito: não move etapa do funil, não manda evento para
 * a Meta e não cria tarefa de remarcação. Corrigir a medição é seguro; refazer os efeitos
 * colaterais retroativamente mexeria no funil de quem já foi trabalhado à mão. As reuniões
 * cujo veredito virou são listadas para decisão humana.
 */
class MeetingsReapurarPresenca extends Command
{
    protected $signature = 'meetings:reapurar-presenca
        {--desde= : só reuniões a partir desta data (Y-m-d)}
        {--aplicar : grava; sem esta flag só mostra o que mudaria}';

    protected $description = 'Recalcula attended/attended_minutes das reuniões já medidas, ignorando a equipe';

    public function handle(): int
    {
        $corte = (int) config('services.crm.attendance_min_minutes', 10);
        $time = (array) config('services.crm.team_display_names', []);
        $aplicar = (bool) $this->option('aplicar');

        $this->line('corte de presença: '.$corte.' min');
        $this->line('equipe ignorada..: '.(implode(', ', $time) ?: '(vazio — nada seria ignorado!)'));
        if (! $time) {
            $this->error('Sem CRM_TEAM_DISPLAY_NAMES configurado não há o que reapurar.');

            return self::FAILURE;
        }

        $q = Meeting::query()
            ->whereNotNull('attendance_checked_at')
            ->whereNotNull('attendees');
        if ($desde = $this->option('desde')) {
            $q->where('starts_at', '>=', $desde);
        }

        $mudaram = [];
        $total = 0;

        foreach ($q->orderBy('starts_at')->cursor() as $m) {
            $total++;
            $novoMin = Meeting::clientMinutes($m->attendees);
            $novo = $novoMin >= $corte;

            if ((bool) $m->attended === $novo && (int) $m->attended_minutes === $novoMin) {
                continue;
            }

            $mudaram[] = [
                $m->id,
                $m->starts_at?->format('d/m H:i'),
                mb_substr((string) $m->title, 0, 30),
                ((bool) $m->attended ? 'compareceu' : 'falta').' '.$m->attended_minutes.'min',
                ($novo ? 'compareceu' : 'falta').' '.$novoMin.'min',
                (bool) $m->attended !== $novo ? 'VEREDITO VIROU' : 'só os minutos',
            ];

            if ($aplicar) {
                // saveQuietly: nada de observer/evento — esta é uma correção de medição.
                $m->attended = $novo;
                $m->attended_minutes = $novoMin;
                $m->saveQuietly();
            }
        }

        $this->newLine();
        $this->table(['id', 'quando', 'título', 'antes', 'depois', 'o que mudou'], $mudaram);
        $this->line('reuniões avaliadas: '.$total.' | corrigidas: '.count($mudaram));

        $viraram = array_filter($mudaram, fn ($r) => $r[5] === 'VEREDITO VIROU');
        if ($viraram) {
            $this->newLine();
            $this->warn(count($viraram).' reunião(ões) trocaram de veredito. A etapa do funil e o');
            $this->warn('evento da Meta NÃO foram desfeitos — confira estes leads à mão.');
        }
        if (! $aplicar) {
            $this->newLine();
            $this->info('>>> Simulação. Rode com --aplicar para gravar.');
        }

        return self::SUCCESS;
    }
}
