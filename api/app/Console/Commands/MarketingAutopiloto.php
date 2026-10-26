<?php

namespace App\Console\Commands;

use App\Models\MarketingAutopilot;
use App\Services\Marketing\Autopilot;
use App\Services\Marketing\AutopilotBrain;
use App\Services\Marketing\Avisos;
use App\Services\Marketing\Gestor;
use App\Services\Marketing\GestorBrain;
use App\Support\Evolution;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Roda o gestor de tráfego (e o piloto automático) das empresas que o ligaram.
 *
 * O agendador chama a cada 10 min; cada empresa é revisada de hora em hora, ou na hora
 * em que alguém aperta "Rodar agora"/"Simular agora" no painel, e recebe o relatório do dia
 * no WhatsApp a partir de `report_hour`. Revisar de hora em hora não é mexer de hora em hora:
 * a trava de 24h por objeto (no {@see Gestor}) é o que protege o aprendizado da Meta — o que a
 * revisão frequente ganha é reagir rápido a saldo, reprovação e teto estourado.
 */
class MarketingAutopiloto extends Command
{
    protected $signature = 'marketing:autopiloto
        {--empresa= : roda só esta empresa, ignorando horário (manual)}
        {--simular : não escreve nada na Meta nem gera imagem}
        {--amostras= : só gera N criativos na biblioteca, sem publicar}
        {--relatorio : só monta e envia o relatório do dia}';

    protected $description = 'Gestor de tráfego: revisa a conta de hora em hora, publica dentro do teto, otimiza pela qualificação e avisa no WhatsApp';

    /** Revisão de hora em hora com folga para o agendador de 10 em 10 min não pular uma. */
    private const INTERVALO_MIN = 55;

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
            $soRelatorio = $manual && $this->option('relatorio');
            $relatorio = $soRelatorio || (! $manual && ! $pedido && $this->horaDoRelatorio($cfg));
            $revisar = $manual ? ! $soRelatorio : ($pedido || $this->horaDaRevisao($cfg));

            if (! $revisar && ! $relatorio) {
                continue;
            }
            // Simulação roda com o piloto desligado (é como se confere antes de ligar);
            // rodada de verdade, nunca.
            if (! $simular && ! $amostras && ! $soRelatorio && ! $cfg->enabled) {
                if ($pedido) {
                    $cfg->update(['run_requested_at' => null, 'run_requested_mode' => null, 'last_result' => 'Piloto desligado — ligue antes de rodar de verdade.']);
                }

                continue;
            }

            // Só a revisão de verdade conta para o relógio — uma simulação não pode fazer o
            // gestor pular a próxima revisão real.
            $real = $revisar && ! $simular && ! $amostras;
            $cfg->update(['run_requested_at' => null, 'run_requested_mode' => null]
                + ($real ? ['last_run_at' => now(), 'last_review_at' => now()] : [])
                + ($relatorio && ! $simular ? ['last_report_at' => now()] : []));

            $tenancy->run((int) $cfg->company_id, function () use ($cfg, $simular, $amostras, $revisar, $relatorio) {
                $fb = FacebookAds::make();
                $piloto = new Autopilot($cfg, $fb, new AutopilotBrain, $simular);
                $gestor = new Gestor($cfg, $fb, $piloto, new GestorBrain, new Avisos($cfg, $simular), $simular);
                try {
                    if ($amostras) {
                        $criados = $piloto->gerarAmostras($amostras);
                        $resumo = count($criados).' criativo(s) gerado(s) na biblioteca: '.collect($criados)->pluck('name')->implode(', ');
                        $this->info($resumo);
                        $cfg->update(['last_result' => $resumo]);

                        return;
                    }

                    if ($revisar) {
                        $relato = $gestor->revisar();
                        foreach ($relato as $r) {
                            $this->line("  [{$r['tipo']}] {$r['msg']}");
                        }
                        $cfg->update(['last_result' => ($simular ? 'Simulação: ' : '').collect($relato)->pluck('msg')->implode("\n")]);
                    }
                    if ($relatorio) {
                        $this->line($gestor->enviarRelatorio() ? '  relatório enviado' : '  relatório não enviado (já enviado hoje ou sem WhatsApp de aviso)');
                    }
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());
                    $cfg->update(['last_result' => 'Falhou: '.$e->getMessage()]);
                    Evolution::log('autopiloto.falha', ['empresa' => $cfg->company_id, 'erro' => $e->getMessage()], 'error');
                }
            });
        }

        return self::SUCCESS;
    }

    private function horaDaRevisao(MarketingAutopilot $cfg): bool
    {
        return $cfg->enabled && (! $cfg->last_review_at || $cfg->last_review_at->lte(now()->subMinutes(self::INTERVALO_MIN)));
    }

    /** Já passou do horário do relatório e ainda não foi o de hoje? */
    private function horaDoRelatorio(MarketingAutopilot $cfg): bool
    {
        if (! $cfg->enabled || blank($cfg->report_hour)) {
            return false;
        }
        [$h, $m] = array_map('intval', explode(':', (string) $cfg->report_hour) + [1 => 0]);

        return now()->gte(now()->setTime($h, $m))
            && (! $cfg->last_report_at || $cfg->last_report_at->lt(now()->startOfDay()));
    }
}
