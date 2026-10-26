<?php

namespace App\Console\Commands;

use App\Models\MarketingAutopilot;
use App\Models\MarketingCreativeRequest;
use App\Services\Marketing\Autopilot;
use App\Services\Marketing\AutopilotBrain;
use App\Services\Marketing\Avisos;
use App\Services\Marketing\DesignerAgente;
use App\Services\Marketing\Gestor;
use App\Services\Marketing\GestorAgente;
use App\Support\Evolution;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * O expediente do gestor de tráfego e do designer, chamado pelo agendador a cada 10 min.
 *
 * A cada chamada, por empresa ligada:
 *   1. vigia sem IA (saldo, reprovação, teto máximo) — pode acordar o gestor antes da hora;
 *   2. turno do GESTOR quando chegou a hora que ele mesmo marcou (ou alguém pediu no painel);
 *   3. o DESIGNER atende um pedido pendente do gestor;
 *   4. relatório do dia no WhatsApp a partir de `report_hour`.
 */
class MarketingAutopiloto extends Command
{
    protected $signature = 'marketing:autopiloto
        {--empresa= : roda o turno do gestor agora só nesta empresa (manual)}
        {--simular : o gestor decide, mas nenhuma ação é executada}
        {--amostras= : só gera N criativos na biblioteca, sem publicar}
        {--relatorio : só monta e envia o relatório do dia}
        {--designer : só atende o próximo pedido ao designer}';

    protected $description = 'Gestor de tráfego (agente) e designer: vigia a conta, roda os turnos e envia o relatório';

    public function handle(Tenancy $tenancy): int
    {
        $alvos = MarketingAutopilot::withoutGlobalScopes()
            ->when($this->option('empresa'), fn ($q, $e) => $q->where('company_id', (int) $e))
            ->get();

        foreach ($alvos as $cfg) {
            $tenancy->run((int) $cfg->company_id, function () use ($cfg) {
                try {
                    $this->expediente($cfg);
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());
                    $cfg->update(['last_result' => 'Falhou: '.$e->getMessage()]);
                    Evolution::log('autopiloto.falha', ['empresa' => $cfg->company_id, 'erro' => $e->getMessage()], 'error');
                }
            });
        }

        return self::SUCCESS;
    }

    private function expediente(MarketingAutopilot $cfg): void
    {
        $manual = (bool) $this->option('empresa');
        $modo = $cfg->run_requested_at ? $cfg->run_requested_mode : null;
        $simular = $manual ? (bool) $this->option('simular') : $modo === 'simular';
        $fb = FacebookAds::make();
        $gestor = new Gestor($cfg, $fb, new Autopilot($cfg, $fb, new AutopilotBrain, $simular), new Avisos($cfg, $simular), $simular);

        if ($modo) {
            $cfg->update(['run_requested_at' => null, 'run_requested_mode' => null]);
        }

        // Amostra (botão do painel): gera direto, sem agente — é para conferir o gerador.
        $amostras = (int) $this->option('amostras') ?: ($modo === 'amostra' ? 1 : 0);
        if ($amostras) {
            $criados = (new Autopilot($cfg, $fb, new AutopilotBrain))->gerarAmostras($amostras);
            $cfg->update(['last_result' => count($criados).' criativo(s) gerado(s): '.collect($criados)->pluck('name')->implode(', ')]);

            return;
        }
        if ($manual && $this->option('relatorio')) {
            $this->line($gestor->enviarRelatorio() ? 'relatório enviado' : 'relatório não enviado');

            return;
        }
        if ($manual && $this->option('designer')) {
            $this->designer($cfg);

            return;
        }

        if (! $cfg->enabled && ! $simular) {
            if ($modo === 'real') {
                $cfg->update(['last_result' => 'Gestor desligado — ligue antes de rodar de verdade.']);
            }

            return;
        }

        $urgente = null;
        if ($cfg->enabled && $fb->configurado()) {
            $urgente = $gestor->vigiar();
        }

        $hora = ! $cfg->next_review_at
            ? (! $cfg->last_review_at || $cfg->last_review_at->lte(now()->subMinutes(GestorAgente::PADRAO_INTERVALO)))
            : $cfg->next_review_at->lte(now());
        // Acordar cedo tem limite: um alerta que se repete não pode virar turno de 10 em 10 min.
        $podeAcordar = $urgente && (! $cfg->last_review_at || $cfg->last_review_at->lte(now()->subMinutes(30)));

        if ($manual || $modo === 'simular' || $modo === 'real' || ($cfg->enabled && ($hora || $podeAcordar))) {
            if (! $simular) {
                // Marca antes de começar: o turno leva minutos e o agendador não pode abrir outro.
                $cfg->update(['last_review_at' => now(), 'next_review_at' => now()->addMinutes(GestorAgente::PADRAO_INTERVALO)]);
            }
            $this->info('Turno do gestor'.($simular ? ' (simulação)' : '').($urgente ? " — urgente: {$urgente}" : ''));
            $s = (new GestorAgente($cfg->fresh(), $simular))->rodar($podeAcordar || $manual ? $urgente : null);
            $this->line("  sessão #{$s->id} {$s->status}, {$s->ferramentas_usadas} ferramenta(s)");
            $this->line((string) ($s->resumo ?? $s->erro));
            $cfg->update(['last_result' => ($simular ? 'Simulação: ' : '').($s->resumo ?? 'Falhou: '.$s->erro)]);
        }

        if ($cfg->enabled && ! $manual) {
            $this->designer($cfg);
            $this->relatorio($cfg, $gestor);
        }
    }

    private function designer(MarketingAutopilot $cfg): void
    {
        $pedido = MarketingCreativeRequest::where('status', MarketingCreativeRequest::PENDENTE)->orderBy('id')->first();
        if (! $pedido) {
            return;
        }
        $this->info("Designer atendendo o pedido #{$pedido->id}");
        $s = (new DesignerAgente($cfg))->atender($pedido);
        $this->line("  sessão #{$s->id} {$s->status}: ".($s->resumo ?? $s->erro));
    }

    private function relatorio(MarketingAutopilot $cfg, Gestor $gestor): void
    {
        if (blank($cfg->report_hour)) {
            return;
        }
        [$h, $m] = array_map('intval', explode(':', (string) $cfg->report_hour) + [1 => 0]);
        if (now()->lt(now()->setTime($h, $m)) || ($cfg->last_report_at && $cfg->last_report_at->gte(now()->startOfDay()))) {
            return;
        }
        $cfg->update(['last_report_at' => now()]);
        $gestor->enviarRelatorio();
    }
}
