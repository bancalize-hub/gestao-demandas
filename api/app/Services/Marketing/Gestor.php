<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutoAd;
use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeRequest;
use App\Models\MarketingMemory;
use App\Support\FacebookAds;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A caixa de ferramentas do gestor de tráfego — o que o AGENTE ({@see GestorAgente}) usa
 * para olhar a conta e agir nela, pelo comando `php artisan gestor`.
 *
 * Quem decide é o agente: ele investiga o que quiser, na ordem que quiser. O que mora aqui
 * são só os limites de dinheiro que o dono definiu, porque esses não podem depender de
 * nenhuma resposta da IA estar certa:
 *   - nada passa do teto em vigor, e o teto nunca passa do máximo;
 *   - teto acima do normal só com custo por lead qualificado dentro da meta;
 *   - verba muda no máximo ±50% por vez (mais que isso é quase sempre erro de digitação,
 *     e reinicia o aprendizado da Meta).
 * Mexer de novo em algo mexido há menos de 24h é permitido, mas exige `forcar` — a
 * ferramenta avisa, o agente decide.
 *
 * A {@see vigiar()} roda sem IA a cada 10 min: avisa saldo e reprovação e, se a conta
 * passar do teto máximo, acorda o agente na hora em vez de esperar o próximo turno.
 */
class Gestor
{
    /** Verba nova entre 1/1,5 e 1,5× a atual. */
    private const PASSO_VERBA = 1.5;

    private const VERBA_MINIMA = 6.0;

    private const TRAVA_HORAS = 24;

    /** Para subir o teto acima do normal, a conta precisa ter provado que qualifica. */
    private const MIN_QUALIFICADOS_PARA_ESCALAR = 3;

    /** Turno do agente que está agindo (para o diário ligar a ação à sessão). */
    private ?int $sessao = null;

    public function __construct(
        private MarketingAutopilot $cfg,
        private FacebookAds $fb,
        private Autopilot $piloto,
        private Avisos $avisos,
        private bool $simular = false,
    ) {}

    public function naSessao(?int $sessao): self
    {
        $this->sessao = $sessao;

        return $this;
    }

    public function simulando(): bool
    {
        return $this->simular;
    }

    // ── 1. fotografia ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function fotografia(): array
    {
        $empresa = (int) $this->cfg->company_id;
        $hoje = $this->fb->gastoPorAnuncio('today');
        $g3 = $this->fb->gastoPorAnuncio('last_3d');
        $g7 = $this->fb->gastoPorAnuncio('last_7d');
        $g30 = $this->fb->gastoPorAnuncio('last_30d');
        $doPiloto = MarketingAutoAd::with('creative:id,number')->get()->keyBy('ad_id');

        $anuncios = [];
        foreach ($this->fb->anunciosDaConta() as $a) {
            $id = (string) $a['id'];
            $ativo = ($a['effective_status'] ?? '') === 'ACTIVE';
            // Parado e sem gasto no mês é arquivo morto: só incha o prompt.
            if (! $ativo && ! isset($g30[$id]) && ! in_array($a['effective_status'] ?? '', ['DISAPPROVED', 'WITH_ISSUES'], true)) {
                continue;
            }
            $anuncios[$id] = $a;
        }

        $ids = array_keys($anuncios);
        $crm3 = QualificacaoCrm::porAnuncio($empresa, $ids, now()->subDays(3)->startOfDay());
        $crm7 = QualificacaoCrm::porAnuncio($empresa, $ids, now()->subDays(7)->startOfDay());
        $crm30 = QualificacaoCrm::porAnuncio($empresa, $ids, now()->subDays(30)->startOfDay());
        $serieCrm = QualificacaoCrm::serieDiaria($empresa, $ids);
        $motivos = QualificacaoCrm::motivosDeReprovacao($empresa, $ids);
        try {
            $serieGasto = $this->fb->gastoDiarioPorAnuncio();
        } catch (\Throwable) {
            $serieGasto = [];
        }

        $linhas = [];
        $orcamentos = [];
        foreach ($anuncios as $id => $a) {
            $camp = $a['campaign'] ?? [];
            $conj = $a['adset'] ?? [];
            $cbo = ! empty($camp['daily_budget']);
            $orcId = (string) ($cbo ? $camp['id'] : ($conj['id'] ?? ''));
            $ativo = ($a['effective_status'] ?? '') === 'ACTIVE';
            $auto = $doPiloto[$id] ?? null;

            if ($orcId !== '' && (! empty($camp['daily_budget']) || ! empty($conj['daily_budget']))) {
                $orcamentos[$orcId] ??= [
                    'id' => $orcId,
                    'nivel' => $cbo ? 'campanha' : 'conjunto',
                    'nome' => (string) ($cbo ? ($camp['name'] ?? '') : ($conj['name'] ?? '')),
                    'diario' => (int) ($cbo ? $camp['daily_budget'] : $conj['daily_budget']) / 100,
                    'entregando' => false,
                    'anuncios' => [],
                ];
                $orcamentos[$orcId]['entregando'] = $orcamentos[$orcId]['entregando'] || $ativo;
                $orcamentos[$orcId]['anuncios'][] = $id;
            }

            $c7 = $crm7[$id] ?? ['leads' => 0, 'triados' => 0, 'qualificados' => 0];
            $gasto7 = (float) ($g7[$id]['gasto'] ?? 0);
            $linhas[] = [
                'id' => $id,
                'nome' => (string) ($a['name'] ?? ''),
                'campanha' => ['id' => (string) ($camp['id'] ?? ''), 'nome' => (string) ($camp['name'] ?? '')],
                'orcamento' => $orcId ?: null,
                'status' => (string) ($a['effective_status'] ?? ''),
                'do_piloto' => (bool) $auto,
                'criativo' => $auto?->creative?->number,
                'dias_no_ar' => isset($a['created_time']) ? (int) now()->diffInDays(Carbon::parse($a['created_time'])->startOfDay(), true) : null,
                'publico' => $this->resumoPublico((array) ($conj['targeting'] ?? [])),
                'gasto' => ['hoje' => (float) ($hoje[$id]['gasto'] ?? 0), '3d' => (float) ($g3[$id]['gasto'] ?? 0), '7d' => $gasto7, '30d' => (float) ($g30[$id]['gasto'] ?? 0)],
                'crm_3d' => $crm3[$id] ?? ['leads' => 0, 'triados' => 0, 'qualificados' => 0],
                'crm_7d' => $c7,
                'crm_30d' => $crm30[$id] ?? ['leads' => 0, 'triados' => 0, 'qualificados' => 0],
                'cpq_7d' => $c7['qualificados'] > 0 ? round($gasto7 / $c7['qualificados'], 2) : null,
                'dia_a_dia' => $this->serie($serieGasto[$id] ?? [], $serieCrm[$id] ?? []),
                'motivos_reprovacao_7d' => $motivos[$id] ?? [],
            ];
        }

        $soma = fn (array $g) => round(array_sum(array_column($g, 'gasto')), 2);
        $q = fn (array $crm) => array_sum(array_column($crm, 'qualificados'));
        // Qualificados de 3d contam hoje também; o gasto 3d da Meta não. Somar o gasto de hoje
        // deixa as duas pontas no mesmo período.
        $gasto3 = $soma($g3) + $soma($hoje);
        $gasto7 = $soma($g7) + $soma($hoje);

        return [
            'saldo' => $this->fb->saldoDisponivel(),
            // 1 = ativa. Sem isto a IA via gasto zero na semana e concluía "conta bloqueada",
            // quando era só a pré-paga zerada.
            'status_conta' => $this->statusConta(),
            'comprometido' => round(array_sum(array_map(fn ($o) => $o['entregando'] ? $o['diario'] : 0, $orcamentos)), 2),
            'teto' => ['normal' => $this->cfg->daily_cap, 'em_vigor' => $this->cfg->tetoVigente(), 'maximo' => $this->cfg->max_cap, 'meta_cpq' => $this->cfg->target_cpq],
            'conta' => [
                'gasto_hoje' => $soma($hoje),
                'gasto_3d_com_hoje' => $gasto3,
                'gasto_7d_com_hoje' => $gasto7,
                'qualificados_3d' => $q($crm3),
                'qualificados_7d' => $q($crm7),
                'cpq_3d' => $q($crm3) > 0 ? round($gasto3 / $q($crm3), 2) : null,
                'cpq_7d' => $q($crm7) > 0 ? round($gasto7 / $q($crm7), 2) : null,
            ],
            'travados_24h' => $this->travados(),
            'orcamentos' => array_values($orcamentos),
            'anuncios' => $linhas,
            'recortes_30d' => $this->recortes(),
            'criativos_30d' => $this->criativos(),
        ];
    }

    /**
     * Uma linha por dia: "24/10 R$ 25,00 · 7 leads · 2 qualif." — só os dias com movimento.
     *
     * @param  array<string, float>  $gasto
     * @param  array<string, array{leads: int, qualificados: int}>  $crm
     * @return list<string>
     */
    private function serie(array $gasto, array $crm): array
    {
        $dias = array_unique(array_merge(array_keys($gasto), array_keys($crm)));
        sort($dias);

        return array_map(fn ($d) => sprintf('%s R$ %s · %d leads · %d qualif.', Carbon::parse($d)->format('d/m'),
            $this->rs($gasto[$d] ?? 0), $crm[$d]['leads'] ?? 0, $crm[$d]['qualificados'] ?? 0), $dias);
    }

    /**
     * Idade, gênero e posicionamento dos últimos 30 dias, com o qualificado que volta pela
     * CAPI (Purchase). Cacheado: o recorte de 30 dias quase não muda de uma hora para outra, e
     * são três chamadas pesadas de insights.
     *
     * @return array<string, mixed>|null
     */
    public function recortes(): ?array
    {
        return Cache::remember("gestor:recortes:{$this->cfg->company_id}", now()->addHours(6), function () {
            try {
                $enxuto = fn (array $linhas) => array_map(fn ($l) => [
                    'recorte' => $l['valor'], 'gasto' => $l['gasto'], 'conversas' => $l['conversas'],
                    'qualificados_capi' => $l['qualificados_capi'] ?? 0, 'custo_qualificado' => $l['custo_qualificado'] ?? null, 'cpm' => $l['cpm'],
                ], array_slice($linhas, 0, 15));

                return [
                    'idade' => $enxuto($this->fb->breakdown('age')),
                    'genero' => $enxuto($this->fb->breakdown('gender')),
                    'posicionamento' => $enxuto($this->fb->breakdown('publisher_platform,platform_position')),
                ];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Histórico por criativo (30 dias, todos os anúncios que usaram a imagem): é o que diz
     * qual registro × gatilho qualifica, e qual criativo de estoque vale testar ou religar.
     *
     * @return list<array<string, mixed>>
     */
    public function criativos(): array
    {
        return Cache::remember("gestor:criativos:{$this->cfg->company_id}", now()->addHours(3), function () {
            try {
                return array_map(fn ($l) => [
                    'criativo' => $l['criativo'], 'arquivo' => $l['arquivo'], 'status' => $l['status'],
                    'registro' => $l['brief']['registro'] ?? null, 'gatilho' => $l['brief']['gatilho'] ?? null,
                    'gasto' => round($l['gasto'], 2), 'leads' => $l['leads'], 'triados' => $l['triados'],
                    'qualificados' => $l['qualificados'], 'cpq' => $l['custo_por_qualificado'] ?? null,
                ], $this->piloto->desempenho());
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public function statusConta(): string
    {
        try {
            $s = (int) ($this->fb->resumoConta()['account_status'] ?? 0);
        } catch (\Throwable) {
            return 'desconhecido';
        }

        return match ($s) {
            1 => 'ativa', 2 => 'DESATIVADA', 3 => 'PAGAMENTO PENDENTE', 7 => 'EM REVISÃO', 9 => 'EM CARÊNCIA', 101 => 'FECHADA', default => "código {$s}"
        };
    }

    /** @param array<string, mixed> $t */
    public function resumoPublico(array $t): string
    {
        if (! $t) {
            return '?';
        }
        $sexo = match ($t['genders'] ?? []) {
            [1] => 'homem', [2] => 'mulher', default => 'todos'
        };
        $onde = implode('+', (array) ($t['publisher_platforms'] ?? ['automático']));

        return sprintf('%s %d-%d %s', $sexo, $t['age_min'] ?? 18, $t['age_max'] ?? 65, $onde);
    }

    // ── vigia (sem IA, a cada 10 min) ─────────────────────────────────────────────

    /**
     * O que não pode esperar o próximo turno do agente. Devolve o motivo para acordá-lo
     * agora, ou null.
     */
    public function vigiar(): ?string
    {
        $e = $this->estrutura();
        $saldo = $this->fb->saldoDisponivel();
        $comp = $e['comprometido'];
        $acordar = null;

        if ($saldo !== null && $saldo <= 0) {
            $this->avisos->enviar('saldo-zero', 'A conta de anúncios está SEM SALDO (R$ 0,00): nada está entregando. '
                .'Recarregue no Gerenciador para os anúncios voltarem.', 12);
        } elseif ($saldo !== null && $comp > 0 && $saldo < 2 * $comp) {
            $this->avisos->enviar('saldo-baixo', sprintf('Saldo da conta: R$ %s — dá ~%s dia(s) no ritmo atual (R$ %s/dia). Recarregue antes de zerar: conta zerada para de entregar e não avisa.',
                $this->rs($saldo), number_format($saldo / $comp, 1, ',', '.'), $this->rs($comp)), 12);
        }

        // Saldo que volta depois de zerado: tudo que estava ACTIVE começa a gastar de uma
        // vez — é exatamente a hora de alguém olhar.
        $saldoAntes = Cache::get("gestor:saldo:{$this->cfg->company_id}");
        Cache::put("gestor:saldo:{$this->cfg->company_id}", $saldo, now()->addDay());
        if ($saldo > 0 && $saldoAntes !== null && $saldoAntes <= 0) {
            $acordar = 'a conta acabou de ser recarregada (R$ '.$this->rs($saldo).') e tudo que estava ativo voltou a gastar';
        }

        foreach ($e['anuncios'] as $a) {
            if (in_array($a['status'], ['DISAPPROVED', 'WITH_ISSUES'], true)) {
                $novo = $this->avisos->enviar('reprovado-'.$a['id'], "A Meta marcou o anúncio \"{$a['nome']}\" como {$a['status']}. "
                    .'Veja o motivo no Gerenciador (Qualidade da conta) — reprovação repetida derruba a conta.', 72);
                $acordar ??= $novo ? "a Meta marcou \"{$a['nome']}\" como {$a['status']}" : null;
            }
        }

        if ($this->cfg->manage_whole_account && $comp > $this->cfg->max_cap) {
            $acordar = sprintf('a conta está com R$ %s/dia comprometidos, acima do teto máximo de R$ %s/dia — reduza já',
                $this->rs($comp), $this->rs($this->cfg->max_cap));
        }

        return $acordar;
    }

    /**
     * Só a estrutura da conta (anúncios, orçamentos, compromisso) — sem insights nem CRM.
     * É o que as ações precisam, e custa uma chamada em vez de dez.
     *
     * @return array{anuncios: array<string, array<string, mixed>>, orcamentos: array<string, array<string, mixed>>, comprometido: float}
     */
    public function estrutura(): array
    {
        $doPiloto = MarketingAutoAd::pluck('ad_id')->flip();
        $anuncios = [];
        $orcamentos = [];
        foreach ($this->fb->anunciosDaConta() as $a) {
            $id = (string) $a['id'];
            $camp = $a['campaign'] ?? [];
            $conj = $a['adset'] ?? [];
            $cbo = ! empty($camp['daily_budget']);
            $orcId = (string) ($cbo ? $camp['id'] : ($conj['id'] ?? ''));
            $ativo = ($a['effective_status'] ?? '') === 'ACTIVE';
            if ($orcId !== '' && ($cbo || ! empty($conj['daily_budget']))) {
                $orcamentos[$orcId] ??= ['id' => $orcId, 'nivel' => $cbo ? 'campanha' : 'conjunto',
                    'nome' => (string) ($cbo ? ($camp['name'] ?? '') : ($conj['name'] ?? '')),
                    'diario' => (int) ($cbo ? $camp['daily_budget'] : $conj['daily_budget']) / 100,
                    'entregando' => false, 'anuncios' => []];
                $orcamentos[$orcId]['entregando'] = $orcamentos[$orcId]['entregando'] || $ativo;
                $orcamentos[$orcId]['anuncios'][] = $id;
            }
            $anuncios[$id] = ['id' => $id, 'nome' => (string) ($a['name'] ?? ''), 'status' => (string) ($a['effective_status'] ?? ''),
                'campanha' => ['id' => (string) ($camp['id'] ?? ''), 'nome' => (string) ($camp['name'] ?? '')],
                'orcamento' => $orcId ?: null, 'do_piloto' => isset($doPiloto[$id])];
        }

        return ['anuncios' => $anuncios, 'orcamentos' => $orcamentos,
            'comprometido' => round(array_sum(array_map(fn ($o) => $o['entregando'] ? $o['diario'] : 0, $orcamentos)), 2)];
    }

    // ── ações ─────────────────────────────────────────────────────────────────────

    public function teto(float $pedido, string $motivo): string
    {
        $atual = $this->cfg->tetoVigente();
        $novo = round(max(0, min($pedido, $this->cfg->max_cap)), 2);
        $limitado = $pedido > $this->cfg->max_cap ? " (pediu R$ {$this->rs($pedido)}; o máximo definido pelo dono é R$ {$this->rs($this->cfg->max_cap)})" : '';

        if ($novo > $this->cfg->daily_cap && $novo > $atual) {
            $c = $this->contaRecente();
            if ($c['cpq_3d'] === null || $c['cpq_3d'] > $this->cfg->target_cpq || $c['qualificados_3d'] < self::MIN_QUALIFICADOS_PARA_ESCALAR) {
                return $this->recusar(sprintf('RECUSADO: teto acima do normal (R$ %s) só com custo por qualificado ≤ R$ %s e ≥ %d qualificados em 3 dias. Agora: %s, %d qualificados.',
                    $this->rs($this->cfg->daily_cap), $this->rs($this->cfg->target_cpq), self::MIN_QUALIFICADOS_PARA_ESCALAR,
                    $this->cpq($c['cpq_3d']), $c['qualificados_3d']));
            }
        }

        if (! $this->simular) {
            $this->cfg->update(['current_cap' => $novo]);
        }

        return $this->acao('teto', 'teto', sprintf('Teto em vigor: R$ %s → R$ %s/dia%s. %s', $this->rs($atual), $this->rs($novo), $limitado, $motivo), ['de' => $atual, 'para' => $novo]);
    }

    public function pausar(string $adId, string $motivo): string
    {
        $e = $this->estrutura();
        $a = $e['anuncios'][$adId] ?? null;
        if (! $a) {
            return $this->recusar("Pausar {$adId}: esse anúncio não existe na conta.");
        }
        if ($erro = $this->foraDoAlcance($a)) {
            return $erro;
        }
        if ($a['status'] !== 'ACTIVE') {
            return "\"{$a['nome']}\" já não está entregando ({$a['status']}). Nada a fazer.";
        }
        $this->pausarAnuncio($adId, $motivo, $a['nome']);
        $o = $e['orcamentos'][$a['orcamento']] ?? null;
        $sobra = $o ? collect($o['anuncios'])->filter(fn ($id) => $id !== $adId && ($e['anuncios'][$id]['status'] ?? '') === 'ACTIVE')->count() : 0;

        return ($this->simular ? '[SIMULAÇÃO — nada foi executado] ' : '')."Pausado \"{$a['nome']}\"."
            .($o && $sobra === 0 ? " O orçamento \"{$o['nome']}\" (R$ {$this->rs($o['diario'])}/dia) ficou sem anúncio entregando — saiu do comprometido." : '')
            .($o && $sobra > 0 ? " O orçamento \"{$o['nome']}\" segue com {$sobra} anúncio(s) entregando e os mesmos R$ {$this->rs($o['diario'])}/dia." : '');
    }

    public function religar(string $adId, string $motivo, bool $forcar = false): string
    {
        $e = $this->estrutura();
        $a = $e['anuncios'][$adId] ?? null;
        if (! $a) {
            return $this->recusar("Religar {$adId}: esse anúncio não existe na conta.");
        }
        if ($erro = $this->foraDoAlcance($a)) {
            return $erro;
        }
        if (! in_array($a['status'], ['PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED'], true)) {
            return "\"{$a['nome']}\" está {$a['status']}, não pausado. Nada a fazer.";
        }
        if (! $forcar && ($this->mexidoHa($adId) || $this->mexidoHa((string) $a['orcamento']))) {
            return "ATENÇÃO: \"{$a['nome']}\" foi mexido nas últimas 24h. Religar de novo reinicia o aprendizado da Meta. Se ainda assim for o certo, repita com --forcar.";
        }
        $o = $e['orcamentos'][$a['orcamento']] ?? null;
        $extra = $o && ! $o['entregando'] ? $o['diario'] : 0;
        if ($this->cfg->tetoVigente() < $e['comprometido'] + $extra) {
            return $this->recusar(sprintf('RECUSADO: religar "%s" soma R$ %s/dia e a conta passaria do teto em vigor (R$ %s comprometidos de R$ %s). Libere verba antes ou ajuste o teto.',
                $a['nome'], $this->rs($extra), $this->rs($e['comprometido']), $this->rs($this->cfg->tetoVigente())));
        }

        $irmaos = [];
        if (! $this->simular) {
            $irmaos = $this->religarSozinho($a);
            if ($auto = MarketingAutoAd::where('ad_id', $adId)->first()) {
                $auto->update(['status' => MarketingAutoAd::ATIVO, 'launched_at' => now(), 'paused_at' => null, 'pause_reason' => null]);
            }
        }

        return $this->acao('religar', $adId, "Religuei \"{$a['nome']}\": {$motivo}", ['orcamento' => $a['orcamento']])
            .($irmaos ? ' Para não voltarem junto, pausei os outros anúncios da campanha: '.implode(', ', $irmaos).'.' : '');
    }

    public function verba(string $orcId, float $valor, string $motivo, bool $forcar = false): string
    {
        $e = $this->estrutura();
        $o = $e['orcamentos'][$orcId] ?? null;
        if (! $o) {
            return $this->recusar("Verba de {$orcId}: não é um orçamento diário desta conta (use o id de \"orcamento\" que aparece no anúncio).");
        }
        if (! $this->cfg->manage_whole_account && ! MarketingAutoAd::whereIn('ad_id', $o['anuncios'])->exists()) {
            return $this->recusar("Verba de \"{$o['nome']}\": o dono limitou o gestor aos anúncios criados por ele.");
        }
        if (! $forcar && $this->mexidoHa($orcId)) {
            return "ATENÇÃO: a verba de \"{$o['nome']}\" foi mexida nas últimas 24h. Se ainda assim for o certo, repita com --forcar.";
        }

        $atual = (float) $o['diario'];
        $novo = round(min(max($valor, $atual / self::PASSO_VERBA, self::VERBA_MINIMA), $atual * self::PASSO_VERBA), 2);
        if (abs($novo - $atual) < 0.5) {
            return "Verba de \"{$o['nome']}\" já está em R$ {$this->rs($atual)}. Nada a fazer.";
        }
        $delta = $o['entregando'] ? $novo - $atual : 0;
        if ($delta > 0 && $this->cfg->tetoVigente() < $e['comprometido'] + $delta) {
            return $this->recusar(sprintf('RECUSADO: verba de "%s" para R$ %s passaria do teto em vigor (R$ %s comprometidos de R$ %s).',
                $o['nome'], $this->rs($novo), $this->rs($e['comprometido']), $this->rs($this->cfg->tetoVigente())));
        }

        if (! $this->simular) {
            $this->fb->definirOrcamento($orcId, $novo);
            MarketingAutoAd::whereIn('ad_id', $o['anuncios'])->update(['daily_budget' => $novo]);
        }
        $ajuste = abs($valor - $novo) >= 0.5 ? " (pediu R$ {$this->rs($valor)}; limitei a ±50% por vez — repita amanhã para ir além)" : '';

        return $this->acao('verba', $orcId, sprintf('Verba de "%s": R$ %s → R$ %s/dia%s. %s', $o['nome'], $this->rs($atual), $this->rs($novo), $ajuste, $motivo),
            ['de' => $atual, 'para' => $novo]);
    }

    /**
     * Sobe um criativo da biblioteca em campanha própria (1 criativo = 1 campanha, ABO),
     * com o público pedido ou o padrão.
     *
     * @param  array<string, mixed>|null  $publico
     */
    public function publicar(int $numero, string $mensagem, ?string $titulo, ?array $publico, ?float $verba, string $motivo): string
    {
        $c = MarketingCreative::where('number', $numero)->first();
        if (! $c) {
            return $this->recusar("Publicar: não existe Criativo {$numero} na biblioteca.");
        }
        if (trim($mensagem) === '') {
            return $this->recusar('Publicar: falta o texto do anúncio (--mensagem).');
        }
        $verba = $verba ? max(self::VERBA_MINIMA, $verba) : $this->cfg->budget_per_ad;
        $e = $this->estrutura();
        if ($this->cfg->tetoVigente() < $e['comprometido'] + $verba) {
            return $this->recusar(sprintf('RECUSADO: R$ %s comprometidos + R$ %s do novo passaria do teto em vigor de R$ %s. Pause algo ou ajuste o teto.',
                $this->rs($e['comprometido']), $this->rs($verba), $this->rs($this->cfg->tetoVigente())));
        }
        $saldo = $this->fb->saldoDisponivel();
        if ($saldo !== null && $saldo < $verba) {
            return $this->recusar('RECUSADO: saldo da conta (R$ '.$this->rs($saldo).') não cobre nem um dia do anúncio.');
        }

        $alvo = $publico ? $this->publicoSeguro($publico) : $this->cfg->publico();
        $resumo = $this->resumoPublico($alvo);
        if ($this->simular) {
            return $this->acao('publicar', 'criativo-'.$numero, "Publicaria o {$c->name} em {$resumo} com R$ {$this->rs($verba)}/dia. {$motivo}");
        }

        $ad = $this->piloto->publicar($c, ['mensagem' => $mensagem, 'titulo' => $titulo], $publico ? "teste {$resumo}" : 'gestor', $publico ? $alvo : null, $verba);
        if (! $ad) {
            $erro = MarketingAutopilotEvent::where('kind', 'erro')->latest('id')->value('message');

            return 'FALHOU ao publicar: '.($erro ?? 'veja o diário');
        }

        return $this->acao('publicar', (string) $ad->ad_id, "Publiquei o {$c->name} em {$resumo} com R$ {$this->rs($verba)}/dia (anúncio {$ad->ad_id}). {$motivo}");
    }

    /** @param list<int> $referencias  @param list<int> $evitar */
    public function pedirCriativo(string $brief, array $referencias, array $evitar, int $quantidade, string $motivo): string
    {
        if (mb_strlen(trim($brief)) < 40) {
            return $this->recusar('Pedido ao designer: o briefing precisa dizer o que a peça comunica, para quem, o registro visual e o porquê (mín. ~40 caracteres).');
        }
        $quantidade = max(1, min(4, $quantidade));
        if ($this->simular) {
            return $this->acao('pedir_criativo', 'designer', "Pediria {$quantidade} criativo(s) ao designer: ".Str::limit($brief, 200));
        }
        $r = MarketingCreativeRequest::create(['brief' => $brief, 'referencias' => $referencias, 'evitar' => $evitar,
            'quantidade' => $quantidade, 'motivo' => $motivo, 'status' => MarketingCreativeRequest::PENDENTE]);

        return $this->acao('pedir_criativo', 'pedido-'.$r->id, "Pedi {$quantidade} criativo(s) ao designer (pedido #{$r->id}): ".Str::limit($brief, 200))
            .' Quando ficarem prontos, aparecem em `gestor criativos --novos` e em `gestor pedidos`.';
    }

    public function anotar(string $categoria, string $titulo, string $conteudo, string $confianca): string
    {
        $titulo = Str::limit(trim($titulo), 180, '');
        if ($titulo === '' || trim($conteudo) === '') {
            return $this->recusar('Anotar: falta título ou conteúdo.');
        }
        if ($this->simular) {
            return "Anotaria na memória: {$titulo}.";
        }
        $m = MarketingMemory::updateOrCreate(['titulo' => $titulo], [
            'categoria' => in_array($categoria, MarketingMemory::CATEGORIAS, true) ? $categoria : 'aprendizado',
            'conteudo' => trim($conteudo).' (gestor automático)',
            'periodo' => now()->format('d/m/Y'),
            'confianca' => $confianca === 'medido' ? 'medido' : 'hipotese',
        ]);
        $this->registrar('memoria', "Anotei na memória de marketing: {$titulo}.");

        return "Anotado (memória #{$m->id}).";
    }

    public function avisar(string $texto): string
    {
        $ok = $this->avisos->enviar('agente-'.md5($texto), $texto, 6);

        return $ok ? 'Aviso enviado ao dono.' : 'Não enviei (mesmo aviso nas últimas 6h, ou WhatsApp de aviso não configurado — ficou no painel).';
    }

    public function voltarEm(int $minutos, string $motivo): string
    {
        $minutos = max(GestorAgente::MIN_INTERVALO, min(GestorAgente::MAX_INTERVALO, $minutos));
        if (! $this->simular) {
            $this->cfg->update(['next_review_at' => now()->addMinutes($minutos)]);
        }
        $this->registrar('agenda', "Próxima revisão em {$minutos} min: {$motivo}");

        return 'Combinado: volto às '.now()->addMinutes($minutos)->format('H:i').'.';
    }

    /** Custo por qualificado da conta nos últimos 3 dias (com hoje). @return array{cpq_3d: ?float, qualificados_3d: int} */
    private function contaRecente(): array
    {
        $g = array_sum(array_column($this->fb->gastoPorAnuncio('last_3d'), 'gasto')) + array_sum(array_column($this->fb->gastoPorAnuncio('today'), 'gasto'));
        $ids = array_keys($this->estrutura()['anuncios']);
        $q = array_sum(array_column(QualificacaoCrm::porAnuncio((int) $this->cfg->company_id, $ids, now()->subDays(3)->startOfDay()), 'qualificados'));

        return ['cpq_3d' => $q > 0 ? round($g / $q, 2) : null, 'qualificados_3d' => $q];
    }

    /**
     * Liga o anúncio e os pais dele SEM ressuscitar os irmãos: anúncio com status próprio
     * ACTIVE dentro de campanha pausada volta junto quando ela liga — e o irmão costuma ser
     * justamente o criativo ruim que motivou a pausa. Pausa esses antes, depois sobe de baixo
     * para cima.
     *
     * @param  array<string, mixed>  $alvo
     * @return list<string> nomes dos irmãos pausados
     */
    private function religarSozinho(array $alvo): array
    {
        $campId = $alvo['campanha']['id'];
        $meu = [];
        $pausados = [];
        foreach ($this->fb->anunciosDaConta() as $ad) {
            if ((string) $ad['id'] === $alvo['id']) {
                $meu = $ad;
            } elseif ((string) ($ad['campaign']['id'] ?? '') === $campId
                && ($ad['status'] ?? '') === 'ACTIVE' && ($ad['effective_status'] ?? '') !== 'ACTIVE') {
                $this->fb->definirStatus((string) $ad['id'], 'PAUSED');
                $pausados[] = (string) ($ad['name'] ?? $ad['id']);
            }
        }

        $this->fb->definirStatus($alvo['id'], 'ACTIVE');
        if (($meu['adset']['status'] ?? 'PAUSED') !== 'ACTIVE' && ! empty($meu['adset']['id'])) {
            $this->fb->definirStatus((string) $meu['adset']['id'], 'ACTIVE');
        }
        if (($meu['campaign']['status'] ?? 'PAUSED') !== 'ACTIVE' && $campId !== '') {
            $this->fb->definirStatus($campId, 'ACTIVE');
        }

        return $pausados;
    }

    /**
     * Só os campos de público que o agente informa sem precisar de id da Meta, em cima do
     * padrão (país, tipo de localização, sem Advantage+). Interesse exige id: pedido de
     * interesse viraria público inválido ou, pior, aberto demais.
     *
     * @param  array<string, mixed>  $pedido
     * @return array<string, mixed>
     */
    private function publicoSeguro(array $pedido): array
    {
        $p = MarketingAutopilot::PUBLICO_PADRAO;
        $p['age_min'] = max(18, min(65, (int) ($pedido['age_min'] ?? $p['age_min'])));
        $p['age_max'] = max($p['age_min'], min(65, (int) ($pedido['age_max'] ?? $p['age_max'])));
        $generos = array_values(array_intersect([1, 2], array_map('intval', (array) ($pedido['genders'] ?? $p['genders']))));
        if ($generos) {
            $p['genders'] = $generos;
        } else {
            unset($p['genders']);
        }

        $plataformas = array_values(array_intersect(['instagram', 'facebook'], (array) ($pedido['publisher_platforms'] ?? $p['publisher_platforms'])));
        $p['publisher_platforms'] = $plataformas ?: ['instagram'];
        unset($p['instagram_positions'], $p['facebook_positions']);
        if (in_array('instagram', $p['publisher_platforms'], true)) {
            $p['instagram_positions'] = array_values(array_intersect(['stream', 'story', 'reels', 'explore'], (array) ($pedido['instagram_positions'] ?? ['stream', 'story', 'reels']))) ?: ['stream', 'story', 'reels'];
        }
        if (in_array('facebook', $p['publisher_platforms'], true)) {
            $p['facebook_positions'] = array_values(array_intersect(['feed', 'story', 'facebook_reels'], (array) ($pedido['facebook_positions'] ?? ['feed']))) ?: ['feed'];
        }

        return $p;
    }

    // ── relatório ─────────────────────────────────────────────────────────────────

    public function relatorio(): string
    {
        $f = $this->fotografia();
        $c = $f['conta'];
        $ativos = collect($f['anuncios'])->where('status', 'ACTIVE')->count();

        $hoje = QualificacaoCrm::porAnuncio((int) $this->cfg->company_id, array_column($f['anuncios'], 'id'), now()->startOfDay());
        $leadsHoje = array_sum(array_column($hoje, 'leads'));
        $qualHoje = array_sum(array_column($hoje, 'qualificados'));

        $linhas = [
            '*Resumo do dia '.now()->format('d/m').'*',
            sprintf('Gasto hoje: R$ %s · %d leads · %d qualificados', $this->rs($c['gasto_hoje']), $leadsHoje, $qualHoje),
            sprintf('Custo por qualificado: 3 dias %s · 7 dias %s (meta R$ %s)', $this->cpq($c['cpq_3d']), $this->cpq($c['cpq_7d']), $this->rs($this->cfg->target_cpq)),
            sprintf('No ar: %d anúncio(s), R$ %s/dia de um teto de R$ %s', $ativos, $this->rs($f['comprometido']), $this->rs($this->cfg->tetoVigente())),
            'Saldo: '.($f['saldo'] === null ? 'não informado' : 'R$ '.$this->rs($f['saldo'])
                .($f['comprometido'] > 0 ? ' (~'.number_format($f['saldo'] / $f['comprometido'], 1, ',', '.').' dias)' : '')),
        ];

        $feitos = MarketingAutopilotEvent::where('simulated', false)->whereIn('kind', ['acao', 'pausou', 'publicou'])
            ->where('created_at', '>=', now()->subDay())->orderBy('id')->pluck('message');
        $linhas[] = '';
        $linhas[] = $feitos->isEmpty() ? '*Decisões:* nenhuma mexida nas últimas 24h.' : '*Decisões (24h):*';
        foreach ($feitos->take(12) as $m) {
            $linhas[] = '• '.Str::limit($m, 160);
        }
        if ($feitos->count() > 12) {
            $linhas[] = '• … e mais '.($feitos->count() - 12).' (ver o diário no painel)';
        }

        if (now()->isMonday()) {
            $linhas[] = '';
            $linhas[] = sprintf('*Semana:* R$ %s gastos, %d qualificados, %s por qualificado.', $this->rs($c['gasto_7d_com_hoje']), $c['qualificados_7d'], $this->cpq($c['cpq_7d']));
            $melhores = collect($f['anuncios'])->filter(fn ($a) => $a['cpq_7d'] !== null)->sortBy('cpq_7d')->take(3);
            foreach ($melhores as $a) {
                $linhas[] = "• melhor: {$a['nome']} — R$ {$this->rs($a['cpq_7d'])}/qualificado";
            }
        }

        if (filled($this->cfg->last_analysis)) {
            $linhas[] = '';
            $linhas[] = '*Leitura do gestor:* '.$this->cfg->last_analysis;
        }

        return implode("\n", $linhas);
    }

    public function enviarRelatorio(): bool
    {
        return $this->avisos->enviar('relatorio-'.now()->format('Y-m-d'), $this->relatorio(), 20);
    }

    // ── apoio ─────────────────────────────────────────────────────────────────────

    private function pausarAnuncio(string $adId, string $motivo, ?string $nome = null): void
    {
        if (! $this->simular) {
            $this->fb->definirStatus($adId, 'PAUSED');
            MarketingAutoAd::where('ad_id', $adId)->where('status', MarketingAutoAd::ATIVO)
                ->update(['status' => MarketingAutoAd::PAUSADO, 'paused_at' => now(), 'pause_reason' => Str::limit("gestor: {$motivo}", 250, '')]);
        }
        $this->acao('pausar', $adId, 'Pausei "'.($nome ?? $adId)."\": {$motivo}");
    }

    /** @param array<string, mixed> $a */
    private function foraDoAlcance(array $a): ?string
    {
        if ($this->cfg->manage_whole_account || $a['do_piloto']) {
            return null;
        }

        return $this->recusar("RECUSADO: \"{$a['nome']}\" foi criado à mão e o dono limitou o gestor aos anúncios criados por ele.");
    }

    /** Objetos em que o gestor mexeu de verdade nas últimas 24h. @return list<string> */
    private function travados(): array
    {
        return MarketingAutopilotEvent::where('kind', 'acao')->where('simulated', false)
            ->where('created_at', '>=', now()->subHours(self::TRAVA_HORAS))
            ->get()->map(fn ($e) => (string) ($e->data['objeto'] ?? ''))->filter()->unique()->values()->all();
    }

    private function mexidoHa(string $objeto): bool
    {
        if ($objeto === '') {
            return false;
        }

        return in_array($objeto, $this->travados(), true)
            // Anúncio do piloto recém-publicado também está em aprendizado.
            || MarketingAutoAd::where(fn ($q) => $q->where('adset_id', $objeto)->orWhere('campaign_id', $objeto)->orWhere('ad_id', $objeto))
                ->where('launched_at', '>=', now()->subHours(self::TRAVA_HORAS))->exists();
    }

    /** As últimas 72h em uma linha cada, para a IA não desfazer amanhã o que fez hoje. @return list<string> */
    public function historico(): array
    {
        return MarketingAutopilotEvent::where('simulated', false)
            ->whereIn('kind', ['acao', 'recusou', 'pausou', 'publicou', 'alerta'])
            ->where('created_at', '>=', now()->subHours(72))
            ->orderByDesc('id')->limit(40)->get()->reverse()
            ->map(fn ($e) => $e->created_at->format('d/m H:i').' ['.$e->kind.'] '.Str::limit($e->message, 220))
            ->values()->all();
    }

    /** @param array<string, mixed> $dados */
    private function acao(string $acao, string $objeto, string $msg, array $dados = []): string
    {
        $this->registrar('acao', $msg, ['acao' => $acao, 'objeto' => $objeto] + $dados);

        return ($this->simular ? '[SIMULAÇÃO — nada foi executado] ' : '').$msg;
    }

    private function recusar(string $msg): string
    {
        $this->registrar('recusou', $msg);

        return $msg;
    }

    private function cpq(?float $v): string
    {
        return $v === null ? 'sem qualificado' : 'R$ '.$this->rs($v);
    }

    private function rs(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }

    /** @param array<string, mixed> $dados */
    private function registrar(string $tipo, string $msg, array $dados = []): void
    {
        MarketingAutopilotEvent::create([
            'kind' => $tipo,
            'message' => $msg,
            'data' => $dados ?: null,
            'simulated' => $this->simular,
            'session_id' => $this->sessao,
        ]);
    }
}
