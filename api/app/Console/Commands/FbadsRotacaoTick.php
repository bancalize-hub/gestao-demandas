<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Conversation;
use App\Support\Evolution;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corta criativo ruim e avisa quando a conta de anúncios para de entregar.
 *
 * Levantamento de 17/08/2026: de 22 criativos que receberam verba, só 9 chegaram a uma
 * amostra legível e 3 viraram vencedores — os outros 13 gastaram e morreram sem resposta,
 * porque a decisão de matar/manter era manual e demorava dias. E a conta é PRÉ-PAGA:
 * com saldo zerado a campanha fica ACTIVE sem entregar nada, sem avisar ninguém.
 *
 * A régua é a qualificação do CRM (chip do lead), nunca a contagem de conversas da Meta —
 * nesta conta lead barato e lead bom andam em direções opostas, medido várias vezes.
 *
 * Padrão é DRY-RUN. Só pausa com --aplicar.
 */
class FbadsRotacaoTick extends Command
{
    protected $signature = 'fbads:rotacao-tick
        {--empresa= : id da empresa (padrão: a principal)}
        {--min-triados=8 : amostra mínima para julgar um criativo}
        {--corte=25 : abaixo desta taxa de qualificação (%), pausa}
        {--dias=14 : janela de leads considerada}
        {--saldo-minimo=50 : avisa quando o saldo da conta cair abaixo disto (R$)}
        {--max-pausas=3 : teto de pausas por rodada}
        {--aplicar : pausa de verdade}';

    protected $description = 'Pausa criativo abaixo do corte de qualificação e alerta saldo da conta de anúncios';

    public function handle(): int
    {
        $empresa = (int) ($this->option('empresa') ?: Company::orderBy('id')->value('id'));
        app(Tenancy::class)->set($empresa);

        $fb = FacebookAds::make();
        if (! $fb->configurado()) {
            $this->warn('Conta de anúncios não configurada.');

            return self::SUCCESS;
        }

        $this->saldo($fb, $empresa);

        $aplicar = (bool) $this->option('aplicar');
        $minTriados = max(3, (int) $this->option('min-triados'));
        $corte = (float) $this->option('corte');
        $maxPausas = max(1, (int) $this->option('max-pausas'));

        // Qualificação por anúncio, direto do CRM: é o ad_id que veio no referral do clique.
        $porAnuncio = DB::table('conversations')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(custom_fields,'$.anuncio.id')) ad_id,
                SUM(qualified IS NOT NULL) triados, SUM(qualified = 1) qualificados")
            ->where('company_id', $empresa)
            ->whereRaw("JSON_EXTRACT(custom_fields,'$.anuncio.id') IS NOT NULL")
            ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
            ->groupBy('ad_id')
            ->having('triados', '>=', $minTriados)
            ->get();

        $ativos = collect($fb->listarAnuncios(500))->keyBy('id');
        $pausados = 0;

        foreach ($porAnuncio as $linha) {
            if ($pausados >= $maxPausas) {
                break;
            }
            $ad = $ativos[$linha->ad_id] ?? null;
            if (! $ad) {
                continue;
            }

            $taxa = 100 * (int) $linha->qualificados / max(1, (int) $linha->triados);
            if ($taxa >= $corte) {
                $this->line(sprintf('  <fg=green>mantém</>  %-28s %2d/%2d triados = %3d%%',
                    mb_substr((string) ($ad['name'] ?? '?'), 0, 28), $linha->qualificados, $linha->triados, $taxa));

                continue;
            }

            $this->line(sprintf('  <fg=red>PAUSA</>   %-28s %2d/%2d triados = %3d%%',
                mb_substr((string) ($ad['name'] ?? '?'), 0, 28), $linha->qualificados, $linha->triados, $taxa));

            if (! $aplicar) {
                continue;
            }

            try {
                $fb->atualizarAnuncio((string) $linha->ad_id, ['status' => 'PAUSED']);
                $pausados++;
                Evolution::log('fbads.rotacao_pausou', [
                    'ad_id' => $linha->ad_id, 'nome' => $ad['name'] ?? null,
                    'triados' => (int) $linha->triados, 'qualificados' => (int) $linha->qualificados,
                    'taxa' => round($taxa),
                ], 'warning');
            } catch (\Throwable $e) {
                $this->error('  falha ao pausar '.$linha->ad_id.': '.mb_substr($e->getMessage(), 0, 70));
            }
        }

        $this->newLine();
        $this->info($aplicar ? "Anúncios pausados: {$pausados}" : 'Simulação — rode com --aplicar para pausar.');

        return self::SUCCESS;
    }

    /**
     * Conta pré-paga sem saldo fica ACTIVE e não entrega — o silêncio é idêntico ao de uma
     * campanha ruim. Ler `funding_source_details`, que é onde mora o saldo de verdade
     * (`balance` é o acumulado a cobrar e `spend_cap` não vale nada em conta pré-paga).
     */
    private function saldo(FacebookAds $fb, int $empresa): void
    {
        try {
            $conta = $fb->resumoConta();
            $texto = (string) ($conta['funding_source_details']['display_string'] ?? '');
            if (! preg_match('/R\$\s?([\d.,]+)/u', $texto, $m)) {
                return;
            }
            $saldo = (float) str_replace(',', '.', str_replace('.', '', $m[1]));
            $minimo = (float) $this->option('saldo-minimo');

            $this->line(sprintf('  saldo da conta: R$ %s', number_format($saldo, 2, ',', '.')));

            if ($saldo >= $minimo) {
                return;
            }

            // O aviso sai pelo log (o quadro de tarefas é só para o que entra à mão, pelo
            // painel ou pelo formulário do cliente — nada de robô). Sem conversa ligada,
            // uma tarefa aqui não apareceria em lugar nenhum e ainda calaria o próximo aviso.
            $this->warn(sprintf('  SALDO BAIXO: R$ %s (mínimo R$ %s)', number_format($saldo, 2, ',', '.'), number_format($minimo, 2, ',', '.')));
            Evolution::log('fbads.saldo_baixo', ['empresa' => $empresa, 'saldo' => $saldo], 'warning');
        } catch (\Throwable $e) {
            // Falha de leitura de saldo não pode derrubar a rotação de criativo.
        }
    }
}
