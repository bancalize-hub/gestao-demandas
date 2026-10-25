<?php

namespace App\Console\Commands;

use App\Models\MarketingAutopilot;
use App\Services\Marketing\Autopilot;
use App\Services\Marketing\AutopilotBrain;
use App\Support\Evolution;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Roda o piloto automático de anúncios das empresas que o ligaram.
 *
 * O agendador chama a cada 10 min; cada empresa roda uma vez por dia (a partir de
 * `--hora`) ou quando alguém aperta "Rodar agora"/"Simular agora" no painel. Uma rodada
 * por dia é de propósito: toda edição em campanha no ar custa um dia de aprendizado da Meta,
 * e decidir com menos de 24h de dado novo é decidir no ruído.
 */
class MarketingAutopiloto extends Command
{
    protected $signature = 'marketing:autopiloto
        {--empresa= : roda só esta empresa, ignorando horário (manual)}
        {--simular : não escreve nada na Meta nem gera imagem}
        {--amostras= : só gera N criativos na biblioteca, sem publicar}
        {--hora=09:30 : a partir de que hora roda a rodada diária}';

    protected $description = 'Piloto automático de anúncios: gera criativo com IA, publica dentro do teto e otimiza pela qualificação';

    public function handle(Tenancy $tenancy): int
    {
        $alvos = MarketingAutopilot::withoutGlobalScopes()
            ->when($this->option('empresa'), fn ($q, $e) => $q->where('company_id', (int) $e))
            ->get();

        foreach ($alvos as $cfg) {
            $manual = (bool) $this->option('empresa');
            $pedido = $cfg->run_requested_at !== null;
            $modo = $pedido ? $cfg->run_requested_mode : null;
            $simular = $manual ? (bool) $this->option('simular') : $modo === 'simular';
            $amostras = (int) $this->option('amostras') ?: ($modo === 'amostra' ? 1 : 0);

            if (! $manual && ! $pedido && ! $this->vezDoDia($cfg)) {
                continue;
            }
            // Simulação roda com o piloto desligado (é como se confere antes de ligar);
            // rodada de verdade, nunca.
            if (! $simular && ! $amostras && ! $cfg->enabled) {
                if ($pedido) {
                    $cfg->update(['run_requested_at' => null, 'run_requested_mode' => null, 'last_result' => 'Piloto desligado — ligue antes de rodar de verdade.']);
                }

                continue;
            }

            // Só a rodada de verdade conta como "a de hoje" — uma simulação de manhã não pode
            // fazer o piloto pular a rodada real do dia.
            $real = ! $simular && ! $amostras;
            $cfg->update(['run_requested_at' => null, 'run_requested_mode' => null] + ($real ? ['last_run_at' => now()] : []));

            $tenancy->run((int) $cfg->company_id, function () use ($cfg, $simular, $amostras) {
                $piloto = new Autopilot($cfg, FacebookAds::make(), new AutopilotBrain, $simular);
                try {
                    if ($amostras) {
                        $criados = $piloto->gerarAmostras($amostras);
                        $resumo = count($criados).' criativo(s) gerado(s) na biblioteca: '.collect($criados)->pluck('name')->implode(', ');
                        $this->info($resumo);
                        $cfg->update(['last_result' => $resumo]);

                        return;
                    }

                    $relato = $piloto->rodar();
                    foreach ($relato as $r) {
                        $this->line("  [{$r['tipo']}] {$r['msg']}");
                    }
                    $cfg->update(['last_result' => ($simular ? 'Simulação: ' : '').collect($relato)->pluck('msg')->implode("\n")]);
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());
                    $cfg->update(['last_result' => 'Falhou: '.$e->getMessage()]);
                    Evolution::log('autopiloto.falha', ['empresa' => $cfg->company_id, 'erro' => $e->getMessage()], 'error');
                }
            });
        }

        return self::SUCCESS;
    }

    /** Já passou da hora de hoje e ainda não rodou de verdade hoje? */
    private function vezDoDia(MarketingAutopilot $cfg): bool
    {
        if (! $cfg->enabled) {
            return false;
        }
        [$h, $m] = array_map('intval', explode(':', (string) $this->option('hora')) + [1 => 0]);

        return now()->gte(now()->setTime($h, $m))
            && (! $cfg->last_run_at || $cfg->last_run_at->lt(now()->startOfDay()));
    }
}
