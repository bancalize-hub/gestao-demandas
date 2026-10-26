<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutoAd;
use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\MarketingCreative;
use App\Models\MarketingCredential;
use App\Models\WaAccount;
use App\Support\FacebookAds;
use App\Support\ImageGen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Piloto automático de anúncios: gera criativo com IA, publica e otimiza sozinho.
 *
 * Uma rodada, em ordem:
 *   1. sincroniza com a Meta o que o robô pôs no ar (pausa feita à mão também conta);
 *   2. julga cada anúncio dele pela QUALIFICAÇÃO DO CRM e pausa o que venceu ou não presta;
 *   3. calcula quanto cabe no teto diário da conta inteira e quantas vagas sobram;
 *   4. preenche as vagas — primeiro com criativo de estoque que nunca rodou, depois com
 *      criativo novo gerado pela IA — e liga cada um com orçamento próprio.
 *
 * As regras vêm do que já foi medido nesta conta, não de manual de mídia:
 * - qualificado/dia por criativo é fixo (~2–3) e não responde a orçamento → teto por
 *   criativo (~R$ 25) e escalar = MAIS criativos, nunca o mesmo maior;
 * - todo criativo de engajamento degenera em 5–7 dias → vida útil fixa, rotacionar antes;
 * - conversa e CTR da Meta mentem sobre qualidade → só o chip de qualificação do CRM decide;
 * - pausar sem repor derruba o volume da conta → toda pausa abre vaga na mesma rodada.
 *
 * Em simulação nada é escrito na Meta e nenhuma imagem é gerada (gerar custa dinheiro);
 * o diário registra o que teria acontecido.
 */
class Autopilot
{
    /** Taxa de qualificação a partir da qual o criativo vira VALIDADO (referência para a IA). */
    private const TAXA_VALIDADO = 60;

    /** Status da Meta que significam "não está entregando e não vai voltar sozinho". */
    private const PARADO = ['PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'DELETED', 'ARCHIVED', 'DISAPPROVED'];

    /** @var list<array{tipo: string, msg: string}> */
    private array $relato = [];

    public function __construct(
        private MarketingAutopilot $cfg,
        private FacebookAds $fb,
        private AutopilotBrain $cerebro,
        private bool $simular = false,
    ) {}

    /** @return list<array{tipo: string, msg: string}> */
    public function rodar(): array
    {
        if (! $this->fb->configurado()) {
            $this->registrar('erro', 'Conta de anúncios não configurada (Admin → Conexão Facebook Ads).');

            return $this->relato;
        }

        $this->sincronizar();
        $liberado = $this->otimizar();
        $this->preencherVagas($liberado);

        return $this->relato;
    }

    /**
     * Gera criativos novos e só guarda na biblioteca, sem publicar — para conferir o
     * gerador de imagem e o estilo antes de ligar o piloto.
     *
     * @return list<MarketingCreative>
     */
    public function gerarAmostras(int $quantos): array
    {
        $plano = $this->cerebro->planejar([], $quantos, $this->desempenho(), $this->cfg);
        $criados = [];
        foreach ($plano['pecas'] as $peca) {
            if ($c = $this->gerarCriativo($peca)) {
                $criados[] = $c;
            }
        }

        return $criados;
    }

    // ── 1. sincronizar ────────────────────────────────────────────────────────────

    private function sincronizar(): void
    {
        $ativos = MarketingAutoAd::where('status', MarketingAutoAd::ATIVO)->get();
        if ($ativos->isEmpty()) {
            return;
        }

        $status = $this->fb->statusDe($ativos->pluck('ad_id')->all());
        foreach ($ativos as $ad) {
            $s = $status[$ad->ad_id] ?? null;
            if ($s && in_array($s, self::PARADO, true)) {
                $motivo = $s === 'DISAPPROVED' ? 'reprovado pela Meta' : "parado fora do piloto ({$s})";
                $ad->update(['status' => MarketingAutoAd::PAUSADO, 'paused_at' => now(), 'pause_reason' => $motivo]);
                $this->registrar('sincronizou', "{$this->nome($ad)}: {$motivo}.", ['ad_id' => $ad->ad_id]);
            }
        }
    }

    // ── 2. otimizar ───────────────────────────────────────────────────────────────

    /** @return float orçamento diário liberado pelas pausas (para a simulação descontar) */
    private function otimizar(): float
    {
        $ativos = MarketingAutoAd::with('creative')->where('status', MarketingAutoAd::ATIVO)->get();
        if ($ativos->isEmpty()) {
            return 0;
        }

        $desde = $ativos->min('launched_at') ?? now()->subDays(30);
        $gastos = $this->fb->gastoPorAnuncio('maximum', $desde->toDateString(), now()->toDateString());
        $crm = QualificacaoCrm::porAnuncio((int) $this->cfg->company_id, $ativos->pluck('ad_id')->all(), $desde);

        $liberado = 0.0;
        foreach ($ativos as $ad) {
            $g = (float) ($gastos[$ad->ad_id]['gasto'] ?? 0);
            $s = $crm[$ad->ad_id] ?? ['leads' => 0, 'triados' => 0, 'qualificados' => 0];
            $taxa = $s['triados'] > 0 ? (int) round(100 * $s['qualificados'] / $s['triados']) : null;
            $dias = (int) $ad->launched_at?->diffInDays(now());
            $resumo = sprintf('R$ %s gastos, %d leads, %d/%d qualificados%s, %d dias',
                number_format($g, 2, ',', '.'), $s['leads'], $s['qualificados'], $s['triados'],
                $taxa === null ? '' : " ({$taxa}%)", $dias);

            $motivo = null;
            $reprova = false;
            if ($s['triados'] >= $this->cfg->min_triados && $taxa < $this->cfg->kill_rate) {
                $motivo = "qualificação {$taxa}% abaixo do corte de {$this->cfg->kill_rate}%";
                $reprova = true;
            } elseif ($g >= $this->cfg->max_spend_no_qualified && $s['qualificados'] === 0) {
                $motivo = 'R$ '.number_format($g, 2, ',', '.').' gastos sem nenhum qualificado';
                $reprova = true;
            } elseif ($dias >= $this->cfg->lifetime_days) {
                $motivo = "vida útil de {$this->cfg->lifetime_days} dias";
            }

            if ($s['triados'] >= $this->cfg->min_triados && $taxa >= self::TAXA_VALIDADO) {
                $this->selar($ad->creative, MarketingCreative::VALIDADO, "Piloto: {$resumo}");
            }

            if (! $motivo) {
                $this->registrar('mantem', "{$this->nome($ad)} segue no ar — {$resumo}.", ['ad_id' => $ad->ad_id]);

                continue;
            }

            if (! $this->simular) {
                try {
                    // Pausa a CAMPANHA (uma por criativo): é ela que solta o orçamento do
                    // compromisso diário da conta e abre a vaga para o substituto.
                    $this->fb->definirStatus((string) $ad->campaign_id, 'PAUSED');
                } catch (\Throwable $e) {
                    $this->registrar('erro', "Não consegui pausar {$this->nome($ad)}: ".$e->getMessage());

                    continue;
                }
                $ad->update(['status' => MarketingAutoAd::PAUSADO, 'paused_at' => now(), 'pause_reason' => $motivo]);
            }
            if ($reprova) {
                $this->selar($ad->creative, MarketingCreative::REPROVADO, "Piloto: {$motivo} ({$resumo})");
            }
            $liberado += $ad->daily_budget;
            $this->registrar('pausou', "{$this->nome($ad)} pausado: {$motivo} — {$resumo}.", ['ad_id' => $ad->ad_id, 'motivo' => $motivo]);
        }

        return $liberado;
    }

    // ── 3 e 4. teto e vagas ───────────────────────────────────────────────────────

    private function preencherVagas(float $liberado): void
    {
        $saldo = $this->fb->saldoDisponivel();
        if ($saldo !== null && $saldo < $this->cfg->budget_per_ad) {
            $this->registrar('sem_saldo', 'Saldo da conta em R$ '.number_format($saldo, 2, ',', '.')
                .' — não crio anúncio novo enquanto a conta não for recarregada (conta pré-paga sem saldo fica ACTIVE e não entrega).');

            return;
        }

        $comprometido = $this->fb->orcamentoComprometido()['total'] - ($this->simular ? $liberado : 0);
        $folga = $this->cfg->tetoVigente() - $comprometido;
        $criadosHoje = MarketingAutoAd::where('launched_at', '>=', now()->startOfDay())->count();
        $vagas = min(
            (int) floor($folga / max(1, $this->cfg->budget_per_ad)),
            $this->cfg->max_new_per_day - $criadosHoje,
        );

        $txt = sprintf('Conta comprometida com R$ %s/dia de um teto de R$ %s.',
            number_format($comprometido, 2, ',', '.'), number_format($this->cfg->tetoVigente(), 2, ',', '.'));
        if ($vagas <= 0) {
            $porque = $folga < $this->cfg->budget_per_ad
                ? ' Sem folga para mais um criativo de R$ '.number_format($this->cfg->budget_per_ad, 2, ',', '.').'.'
                : " Já criei {$criadosHoje} hoje (limite {$this->cfg->max_new_per_day}/dia).";
            $this->registrar('teto', $txt.$porque, ['comprometido' => $comprometido]);

            return;
        }
        $this->registrar('teto', $txt." Cabem {$vagas} criativo(s) novo(s).", ['comprometido' => $comprometido, 'vagas' => $vagas]);

        $estoque = $this->cfg->use_stock ? $this->estoque($vagas) : [];
        try {
            $plano = $this->cerebro->planejar($estoque, $vagas - count($estoque), $this->desempenho(), $this->cfg);
        } catch (\Throwable $e) {
            $this->registrar('erro', 'Planejamento falhou: '.$e->getMessage());

            return;
        }

        foreach ($estoque as $c) {
            $copy = $plano['copys'][$c->number] ?? null;
            if (! $copy) {
                $this->registrar('erro', "A IA não escreveu a copy do {$c->name} — fica para a próxima rodada.");

                continue;
            }
            $this->publicar($c, $copy, 'estoque');
        }

        foreach ($plano['pecas'] as $peca) {
            if ($this->simular) {
                $this->registrar('geraria', 'Geraria e publicaria: '.($peca['registro'] ?? '?').' × '.($peca['gatilho'] ?? '?')
                    .' — "'.($peca['manchete'] ?? '').'"', ['peca' => $peca]);

                continue;
            }
            if ($c = $this->gerarCriativo($peca)) {
                $this->publicar($c, ['mensagem' => $peca['mensagem'] ?? '', 'titulo' => $peca['titulo'] ?? null], 'gerado');
            }
        }
    }

    /**
     * Criativos de estoque que nunca receberam verba, na ordem do que mais qualifica nesta
     * conta: o registro editorial (jornal / classificado) filtra o lead ruim antes do clique.
     *
     * @return list<MarketingCreative>
     */
    private function estoque(int $limite): array
    {
        $usados = array_keys($this->fb->anunciosPorImagem());
        $doRobo = MarketingAutoAd::pluck('marketing_creative_id')->all();

        return MarketingCreative::where('status', MarketingCreative::TESTANDO)
            ->whereNotIn('id', $doRobo)
            ->get()
            ->reject(fn (MarketingCreative $c) => filled($c->fb_image_hash) && in_array($c->fb_image_hash, $usados, true))
            ->sortBy(fn (MarketingCreative $c) => preg_match('/jornal|classificado|capa|cartaz/i', (string) $c->original_name) ? 0 : 1)
            ->take($limite)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $peca */
    public function gerarCriativo(array $peca): ?MarketingCreative
    {
        try {
            $gerador = ImageGen::make($this->cfg);
        } catch (RuntimeException $e) {
            $this->registrar('erro', $e->getMessage());

            return null;
        }

        // Duas tentativas: o texto dentro da imagem sai errado de vez em quando, e a
        // conferência abaixo é o que impede um erro de português de ir para o feed.
        for ($tentativa = 1; $tentativa <= 2; $tentativa++) {
            try {
                $png = $gerador->gerar($this->cerebro->promptImagem($peca));
            } catch (\Throwable $e) {
                $this->registrar('erro', 'Falha ao gerar imagem: '.$e->getMessage());

                return null;
            }

            $path = 'marketing/creatives/ia-'.Str::uuid().'.png';
            Storage::put($path, $png);

            $conferencia = $this->cerebro->conferirImagem(Storage::path($path), $peca);
            if ($conferencia['ok']) {
                return DB::transaction(function () use ($peca, $path, $png, $gerador) {
                    $n = MarketingCreative::lockForUpdate()->max('number') + 1;

                    return MarketingCreative::create([
                        'name' => "Criativo {$n}",
                        'number' => $n,
                        'original_name' => sprintf('%02d-%s-%s-ia.png', $n, Str::slug($peca['registro'] ?? 'ia'), Str::slug($peca['gatilho'] ?? 'ia')),
                        'path' => $path,
                        'mime' => 'image/png',
                        'size' => strlen($png),
                        'notes' => trim(($peca['manchete'] ?? '').' — gerado pela IA ('.$gerador->modelo().')'),
                        'source' => 'ia',
                        'brief' => $peca,
                        'status' => MarketingCreative::TESTANDO,
                    ]);
                });
            }

            Storage::delete($path);
            $this->registrar('descartou', "Imagem descartada na conferência (tentativa {$tentativa}): ".$conferencia['motivo']);
        }

        return null;
    }

    /**
     * @param  array{mensagem: string, titulo?: ?string}  $copy
     * @param  array<string, mixed>|null  $publico  público diferente do padrão (teste do gestor)
     */
    public function publicar(MarketingCreative $c, array $copy, string $origem, ?array $publico = null, ?float $verba = null): ?MarketingAutoAd
    {
        $wa = WaAccount::primary();
        $fone = preg_replace('/\D/', '', (string) $wa?->phone);
        $pagina = trim((string) MarketingCredential::atual()->page_id);
        if ($fone === '' || $pagina === '') {
            $this->registrar('erro', 'Falta o número principal do WhatsApp ou a página do Facebook — não dá para montar o anúncio.');

            return null;
        }

        $verba ??= $this->cfg->budget_per_ad;
        if ($this->simular) {
            $this->registrar('publicaria', "Publicaria o {$c->name} ({$origem}) com R$ ".number_format($verba, 2, ',', '.').'/dia: "'
                .Str::limit($copy['mensagem'], 120).'"', ['criativo' => $c->number, 'copy' => $copy]);

            return null;
        }

        // Teto conferido de novo logo antes de ligar: entre o começo da rodada e aqui a
        // conta pode ter ganhado campanha (gente mexendo no Gerenciador ao mesmo tempo).
        $comprometido = $this->fb->orcamentoComprometido()['total'];
        if ($comprometido + $verba > $this->cfg->tetoVigente()) {
            $this->registrar('teto', "Não publiquei o {$c->name}: R$ {$comprometido}/dia + R$ {$verba} passaria do teto.");

            return null;
        }

        // O prefill "Criativo N" é o que o LeadsDeAnuncio casa para saber de qual anúncio
        // veio o lead (o referral também traz o ad_id, mas não em todo clique).
        $link = "https://api.whatsapp.com/send?phone={$fone}&text=".urlencode($c->name);
        $rotulo = trim(($c->brief['registro'] ?? '').' · '.($c->brief['gatilho'] ?? ''), ' ·') ?: $origem;

        $auto = null;
        try {
            $camp = $this->fb->criarCampanha("AUTO · {$c->name} · {$rotulo}", 'OUTCOME_ENGAGEMENT');
            $conj = $this->fb->criarConjunto(
                $camp['id'], "AUTO · {$c->name} · R$ ".number_format($verba, 0), $verba, 'CONVERSATIONS',
                $publico ?? $this->cfg->publico(), 'IMPRESSIONS', 'WHATSAPP',
                ['page_id' => $pagina, 'whatsapp_phone_number' => $fone],
            );
            $ad = $this->fb->criarAnuncio($conj['id'], $c->name, $c, $copy['mensagem'], $copy['titulo'] ?? null, $link, null, 'WHATSAPP_MESSAGE');

            $auto = MarketingAutoAd::create([
                'marketing_creative_id' => $c->id,
                'campaign_id' => $camp['id'],
                'adset_id' => $conj['id'],
                'ad_id' => $ad['id'],
                'daily_budget' => $verba,
                'status' => MarketingAutoAd::PAUSADO,
                'pause_reason' => 'criado, ainda não ligado',
                'copy' => $copy,
            ]);

            // De baixo para cima: a entrega só começa no último flip (campanha), então
            // nunca há um instante com a campanha ligada e o filho pela metade.
            $this->fb->definirStatus($ad['id'], 'ACTIVE');
            $this->fb->definirStatus($conj['id'], 'ACTIVE');
            $this->fb->definirStatus($camp['id'], 'ACTIVE');

            $auto->update(['status' => MarketingAutoAd::ATIVO, 'launched_at' => now(), 'pause_reason' => null]);
            $this->registrar('publicou', "Publiquei o {$c->name} ({$origem}) com R$ ".number_format($verba, 2, ',', '.').'/dia.', [
                'criativo' => $c->number, 'campaign_id' => $camp['id'], 'ad_id' => $ad['id'],
            ]);
        } catch (\Throwable $e) {
            $this->registrar('erro', "Falha ao publicar o {$c->name}: ".$e->getMessage()
                .($auto ? ' — o que foi criado ficou PAUSADO.' : ''));

            return null;
        }

        return $auto;
    }

    // ── dados ─────────────────────────────────────────────────────────────────────

    /**
     * Desempenho dos últimos 30 dias por criativo — é o que a IA lê para decidir o que
     * repetir e o que evitar. Custo por QUALIFICADO, nunca por lead.
     *
     * @return list<array<string, mixed>>
     */
    public function desempenho(): array
    {
        try {
            $porImagem = $this->fb->anunciosPorImagem();
            $gastos = $this->fb->gastoPorAnuncio('last_30d');
        } catch (\Throwable) {
            $porImagem = [];
            $gastos = [];
        }

        $adIds = collect($porImagem)->flatten(1)->pluck('id')->all();
        $crm = QualificacaoCrm::porAnuncio((int) $this->cfg->company_id, $adIds, now()->subDays(30));

        $linhas = [];
        foreach (MarketingCreative::orderBy('number')->get() as $c) {
            $l = ['criativo' => $c->number, 'arquivo' => $c->original_name, 'notas' => $c->notes,
                'status' => $c->status, 'brief' => $c->brief, 'gasto' => 0.0, 'leads' => 0, 'triados' => 0, 'qualificados' => 0];
            foreach ($c->fb_image_hash ? ($porImagem[$c->fb_image_hash] ?? []) : [] as $ad) {
                $l['gasto'] += (float) ($gastos[$ad['id']]['gasto'] ?? 0);
                foreach (['leads', 'triados', 'qualificados'] as $k) {
                    $l[$k] += $crm[$ad['id']][$k] ?? 0;
                }
            }
            if ($l['gasto'] > 0 || $l['leads'] > 0 || $c->status !== MarketingCreative::TESTANDO) {
                $l['custo_por_qualificado'] = $l['qualificados'] > 0 ? round($l['gasto'] / $l['qualificados'], 2) : null;
                $linhas[] = $l;
            }
        }

        return $linhas;
    }

    private function selar(?MarketingCreative $c, string $status, string $nota): void
    {
        if (! $c || $c->status === $status || $this->simular) {
            return;
        }
        $c->update(['status' => $status, 'status_note' => $nota, 'status_at' => now()]);
    }

    private function nome(MarketingAutoAd $ad): string
    {
        return $ad->creative?->name ?? "anúncio {$ad->ad_id}";
    }

    /** @param array<string, mixed> $dados */
    private function registrar(string $tipo, string $msg, array $dados = []): void
    {
        $this->relato[] = ['tipo' => $tipo, 'msg' => $msg];
        MarketingAutopilotEvent::create([
            'kind' => $tipo,
            'message' => $msg,
            'data' => $dados ?: null,
            'simulated' => $this->simular,
        ]);
    }
}
