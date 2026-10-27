<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SdrAgent;
use App\Services\Sdr\Sdr;
use App\Services\Sdr\SdrAgente;
use App\Support\Attendance;
use App\Support\Evolution;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * O expediente do agente SDR, chamado pelo agendador a cada 5 min. Por empresa ligada:
 * vigia sem IA (lead que faltou, retorno vencido) e, se chegou a hora que o agente marcou ou
 * algo não pode esperar, roda um turno — só em horário de atendimento, que é quando ele
 * consegue mandar alguma coisa.
 *
 * Nome fora do prefixo `sdr`: o agente tem licença para `php artisan sdr…` e não pode
 * conseguir disparar o próprio turno.
 */
class AgenteSdrTick extends Command
{
    protected $signature = 'agente-sdr:tick
        {--empresa= : roda um turno agora só nesta empresa (manual)}
        {--simular : manual: decide, mas não envia nada}';

    protected $description = 'Agente SDR: vigia as conversas e roda o turno quando é hora';

    public function handle(Tenancy $tenancy): int
    {
        $alvos = SdrAgent::withoutGlobalScopes()
            ->when($this->option('empresa'), fn ($q, $e) => $q->where('company_id', (int) $e))
            ->get();

        foreach ($alvos as $cfg) {
            $tenancy->run((int) $cfg->company_id, function () use ($cfg) {
                try {
                    $this->expediente($cfg);
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());
                    $cfg->update(['last_result' => 'Falhou: '.mb_substr($e->getMessage(), 0, 200)]);
                    Evolution::log('sdr.falha', ['empresa' => $cfg->company_id, 'erro' => $e->getMessage()], 'error');
                }
            });
        }

        return self::SUCCESS;
    }

    private function expediente(SdrAgent $cfg): void
    {
        $manual = (bool) $this->option('empresa');
        $modo = $cfg->run_requested_at ? $cfg->run_requested_mode : null;
        if ($modo) {
            $cfg->update(['run_requested_at' => null, 'run_requested_mode' => null]);
        }
        $simular = $manual ? (bool) $this->option('simular') : $modo === 'simular';

        if ($manual || $modo) {
            if (! $simular && ! $cfg->enabled) {
                $cfg->update(['last_result' => 'SDR desligado — ligue antes de rodar de verdade.']);

                return;
            }
            $this->turno($cfg, $simular, $modo ? 'pedido pelo painel' : null);

            return;
        }

        if (! $cfg->enabled || ! Attendance::dentro(Company::find($cfg->company_id))) {
            return;
        }

        $hora = ! $cfg->next_review_at || $cfg->next_review_at->lte(now());
        $urgente = (new Sdr($cfg))->urgente();
        // Acordar cedo tem limite: um alerta que se repete não vira turno de 5 em 5 min.
        $podeAcordar = $urgente && (! $cfg->last_review_at || $cfg->last_review_at->lte(now()->subMinutes(20)));

        if ($hora || $podeAcordar) {
            $this->turno($cfg, false, $hora ? null : $urgente);
        }
    }

    private function turno(SdrAgent $cfg, bool $simular, ?string $urgente): void
    {
        $this->line('SDR: turno '.($simular ? 'simulado ' : '')."da empresa {$cfg->company_id}…");
        $s = (new SdrAgente($cfg, $simular))->rodar($urgente);
        if ($simular) {
            $cfg->update(['last_result' => 'Simulação concluída ('.$s->status.')']);
        }
        $this->line("SDR: sessão {$s->id} {$s->status}");
    }
}
