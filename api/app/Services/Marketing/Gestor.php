<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutoAd;
use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\MarketingCreative;
use App\Models\MarketingMemory;
use App\Support\FacebookAds;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Gestor de tráfego: revisa a conta INTEIRA de hora em hora e decide sozinho.
 *
 * Uma revisão, em ordem:
 *   1. fotografa a conta (anúncios, orçamentos, gasto, qualificação do CRM, saldo);
 *   2. vigia — o que não depende de opinião: saldo acabando, anúncio reprovado pela Meta
 *      e teto máximo estourado (esse ele corta na hora, do pior para o melhor);
 *   3. pergunta à IA o que fazer e executa só o que passa nas travas;
 *   4. entrega para o {@see Autopilot}, que aplica as regras fixas nos anúncios dele e
 *      preenche a folga do teto em vigor com criativo novo.
 *
 * A IA propõe; as travas daqui é que garantem que nenhuma resposta dela — por pior que
 * seja — passe do teto máximo, mexa duas vezes no mesmo conjunto em 24h ou triplique uma
 * verba de uma vez.
 */
class Gestor
{
    private const MAX_ACOES = 10;

    /** Verba nova entre 1/1,5 e 1,5× a atual: mudança maior reinicia o aprendizado da Meta. */
    private const PASSO_VERBA = 1.5;

    private const VERBA_MINIMA = 6.0;

    private const TRAVA_HORAS = 24;

    /** Para subir o teto acima do normal, a conta precisa ter provado que qualifica. */
    private const MIN_QUALIFICADOS_PARA_ESCALAR = 3;

    /** @var list<array{tipo: string, msg: string}> */
    private array $relato = [];

    public function __construct(
        private MarketingAutopilot $cfg,
        private FacebookAds $fb,
        private Autopilot $piloto,
        private GestorBrain $cerebro,
        private Avisos $avisos,
        private bool $simular = false,
    ) {}

    /** @return list<array{tipo: string, msg: string}> */
    public function revisar(): array
    {
        if (! $this->fb->configurado()) {
            return $this->piloto->rodar(); // ele mesmo registra "não configurada"
        }

        try {
            $foto = $this->fotografia();
        } catch (\Throwable $e) {
            $this->registrar('erro', 'Não consegui ler a conta na Meta: '.$e->getMessage());
            $this->falhou('leitura', 'Não consigo ler a conta de anúncios na Meta: '.$e->getMessage());

            return $this->relato;
        }

        $this->vigiar($foto);

        // Sem saldo nada entrega e os números não mudam: analisar de hora em hora só gasta IA.
        // Ainda assim analisa a cada 6h — é a hora de limpar a conta para a volta do saldo.
        $semSaldo = $foto['saldo'] !== null && $foto['saldo'] <= 0;
        $analisouHa = MarketingAutopilotEvent::where('kind', 'analise')->where('simulated', false)
            ->where('created_at', '>=', now()->subHours(6))->exists();
        if ($semSaldo && $analisouHa && ! $this->simular) {
            $this->registrar('pulou', 'Conta sem saldo e já analisada há menos de 6h: espero a recarga ou a próxima janela.');
        } else {
            $this->decidir($foto);
        }

        return array_merge($this->relato, $this->piloto->rodar());
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
    private function recortes(): ?array
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
    private function criativos(): array
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

    /** @param array<string, mixed> $t */
    private function resumoPublico(array $t): string
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

    // ── 2. vigiar ─────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $foto */
    private function vigiar(array &$foto): void
    {
        $saldo = $foto['saldo'];
        $comp = (float) $foto['comprometido'];
        if ($saldo !== null && $saldo <= 0) {
            $this->avisos->enviar('saldo-zero', 'A conta de anúncios está SEM SALDO (R$ 0,00): nada está entregando. '
                .'Recarregue no Gerenciador para os anúncios voltarem.', 12);
        } elseif ($saldo !== null && $comp > 0 && $saldo < 2 * $comp) {
            $this->avisos->enviar('saldo-baixo', sprintf('Saldo da conta: R$ %s — dá ~%s dia(s) no ritmo atual (R$ %s/dia). Recarregue antes de zerar: conta zerada para de entregar e não avisa.',
                $this->rs($saldo), number_format($saldo / $comp, 1, ',', '.'), $this->rs($comp)), 12);
        }

        foreach ($foto['anuncios'] as $a) {
            if (in_array($a['status'], ['DISAPPROVED', 'WITH_ISSUES'], true)) {
                $this->avisos->enviar('reprovado-'.$a['id'], "A Meta marcou o anúncio \"{$a['nome']}\" como {$a['status']}. "
                    .'Veja o motivo no Gerenciador (Qualidade da conta) — reprovação repetida derruba a conta.', 72);
            }
        }

        // O teto máximo é o único limite que nunca depende da IA: estourou, corta já.
        if (! $this->cfg->manage_whole_account || $comp <= $this->cfg->max_cap) {
            return;
        }

        $porAnuncio = collect($foto['anuncios'])->keyBy('id');
        $candidatos = collect($foto['orcamentos'])->where('entregando', true)->map(function ($o) use ($porAnuncio) {
            $gasto = $porAnuncio->only($o['anuncios'])->sum(fn ($a) => $a['gasto']['7d']);
            $qual = $porAnuncio->only($o['anuncios'])->sum(fn ($a) => $a['crm_7d']['qualificados']);

            // Sem qualificado nenhum = pior de todos, e entre esses, quem gastou mais.
            return $o + ['cpq' => $qual > 0 ? $gasto / $qual : INF, 'gasto7' => $gasto];
        })->sort(fn ($a, $b) => [$b['cpq'], $b['gasto7']] <=> [$a['cpq'], $a['gasto7']])->values();

        $cortados = [];
        $idsCortados = [];
        foreach ($candidatos as $o) {
            if ($comp <= $this->cfg->max_cap) {
                break;
            }
            foreach ($o['anuncios'] as $adId) {
                if (($porAnuncio[$adId]['status'] ?? '') === 'ACTIVE') {
                    $this->pausarAnuncio($adId, "teto máximo de R$ {$this->rs($this->cfg->max_cap)}/dia estourado — corte do pior custo por qualificado");
                }
            }
            $comp -= $o['diario'];
            $idsCortados[] = $o['id'];
            $cortados[] = "{$o['nome']} (R$ {$this->rs($o['diario'])}/dia)";
        }
        // A IA decide em cima da conta JÁ cortada — senão ela "religa" o que acabou de sair.
        $foto['comprometido'] = round($comp, 2);
        $foto['orcamentos'] = array_map(fn ($o) => in_array($o['id'], $idsCortados, true) ? ['entregando' => false] + $o : $o, $foto['orcamentos']);
        $foto['anuncios'] = array_map(fn ($a) => in_array($a['orcamento'], $idsCortados, true) && $a['status'] === 'ACTIVE' ? ['status' => 'PAUSED'] + $a : $a, $foto['anuncios']);

        if ($cortados) {
            $this->avisos->enviar('teto-max-'.now()->format('YmdH'), 'A conta passou do teto máximo e eu cortei: '.implode(', ', $cortados).'.', 1);
        }
    }

    // ── 3. decidir ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $foto */
    private function decidir(array $foto): void
    {
        if (! $this->cfg->manage_whole_account) {
            $foto['anuncios'] = array_values(array_filter($foto['anuncios'], fn ($a) => $a['do_piloto'] || $a['status'] === 'ACTIVE'));
        }

        try {
            $plano = $this->cerebro->decidir($foto, $this->cfg, $this->historico());
        } catch (\Throwable $e) {
            $this->registrar('erro', 'Revisão da IA falhou: '.$e->getMessage());
            $this->falhou('ia', 'A revisão da conta pela IA está falhando: '.$e->getMessage());

            return;
        }

        $diag = collect($plano['diagnosticos'])->map(fn ($d) => '• '.Str::limit((string) ($d['nome'] ?? $d['anuncio'] ?? '?'), 60)
            .' → '.strtoupper((string) ($d['veredito'] ?? '?')).': '.($d['porque'] ?? ''))->implode("\n");
        $leitura = trim($plano['analise'].($diag !== '' ? "\n\nPor anúncio:\n{$diag}" : ''));
        if ($leitura !== '') {
            $this->registrar('analise', $leitura, ['diagnosticos' => $plano['diagnosticos']]);
            if (! $this->simular) {
                $this->cfg->update(['last_analysis' => $leitura]);
            }
        }

        $this->ajustarTeto($plano['teto'], $foto);

        $anuncios = collect($foto['anuncios'])->keyBy('id');
        $orcamentos = collect($foto['orcamentos'])->keyBy('id');
        foreach (array_slice($plano['acoes'], 0, self::MAX_ACOES) as $acao) {
            $motivo = trim((string) ($acao['motivo'] ?? '')) ?: 'sem motivo informado';
            try {
                match ($acao['acao'] ?? null) {
                    'pausar' => $this->acaoPausar((string) ($acao['anuncio'] ?? ''), $motivo, $anuncios),
                    'religar' => $this->acaoReligar((string) ($acao['anuncio'] ?? ''), $motivo, $anuncios, $orcamentos, $foto),
                    'verba' => $this->acaoVerba((string) ($acao['orcamento'] ?? ''), (float) ($acao['valor'] ?? 0), $motivo, $orcamentos, $foto),
                    'testar_publico' => $this->acaoTestarPublico($acao, $motivo),
                    default => $this->recusar('ação desconhecida: '.json_encode($acao, JSON_UNESCAPED_UNICODE)),
                };
            } catch (\Throwable $e) {
                $this->registrar('erro', 'Falhou ao executar '.($acao['acao'] ?? '?').': '.$e->getMessage());
            }
        }
        if (count($plano['acoes']) > self::MAX_ACOES) {
            $this->recusar('A IA pediu '.count($plano['acoes']).' ações; executei só as '.self::MAX_ACOES.' primeiras.');
        }

        foreach (array_slice($plano['memorias'], 0, 2) as $m) {
            $this->memorizar($m);
        }
        if ($plano['alerta']) {
            $this->avisos->enviar('ia-'.md5($plano['alerta']), $plano['alerta'], 12);
        }
    }

    /** @param array<string, mixed> $foto */
    private function ajustarTeto(?float $pedido, array $foto): void
    {
        if ($pedido === null) {
            return;
        }
        $atual = $this->cfg->tetoVigente();
        $novo = round(max(0, min($pedido, $this->cfg->max_cap)), 2);
        if (abs($novo - $atual) < 0.01) {
            return;
        }

        if ($novo > $this->cfg->daily_cap && $novo > $atual) {
            $cpq = $foto['conta']['cpq_3d'];
            $q = $foto['conta']['qualificados_3d'];
            if ($cpq === null || $cpq > $this->cfg->target_cpq || $q < self::MIN_QUALIFICADOS_PARA_ESCALAR) {
                $this->recusar(sprintf('Não subi o teto para R$ %s: acima do normal só com custo por qualificado ≤ R$ %s e ≥ %d qualificados em 3 dias (está %s, %d qualificados).',
                    $this->rs($novo), $this->rs($this->cfg->target_cpq), self::MIN_QUALIFICADOS_PARA_ESCALAR,
                    $cpq === null ? 'sem qualificado' : 'R$ '.$this->rs($cpq), $q));

                return;
            }
            if ($this->mexidoHa('teto')) {
                $this->recusar("Não subi o teto para R$ {$this->rs($novo)}: já subi nas últimas 24h.");

                return;
            }
        }

        if (! $this->simular) {
            $this->cfg->update(['current_cap' => $novo]);
        }
        $this->acao('teto', 'teto', sprintf('Teto em vigor: R$ %s → R$ %s/dia.', $this->rs($atual), $this->rs($novo)), ['de' => $atual, 'para' => $novo]);
    }

    private function acaoPausar(string $adId, string $motivo, $anuncios): void
    {
        $a = $anuncios[$adId] ?? null;
        if (! $a) {
            $this->recusar("Pausar {$adId}: anúncio não está na conta.");

            return;
        }
        if (! $this->podeMexer($a)) {
            return;
        }
        if ($a['status'] !== 'ACTIVE') {
            return; // já parado: não é decisão, é ruído
        }
        $this->pausarAnuncio($adId, $motivo, $a['nome']);
    }

    private function acaoReligar(string $adId, string $motivo, $anuncios, $orcamentos, array &$foto): void
    {
        $a = $anuncios[$adId] ?? null;
        if (! $a) {
            $this->recusar("Religar {$adId}: anúncio não está na conta.");

            return;
        }
        if (! $this->podeMexer($a)) {
            return;
        }
        if (! in_array($a['status'], ['PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED'], true)) {
            $this->recusar("Religar \"{$a['nome']}\": está {$a['status']}, não pausado.");

            return;
        }
        if ($this->mexidoHa($adId) || $this->mexidoHa((string) $a['orcamento'])) {
            $this->recusar("Religar \"{$a['nome']}\": mexido nas últimas 24h.");

            return;
        }
        $orc = $orcamentos[$a['orcamento']] ?? null;
        $extra = $orc && ! $orc['entregando'] ? $orc['diario'] : 0;
        if ($this->cfg->tetoVigente() < $foto['comprometido'] + $extra) {
            $this->recusar(sprintf('Religar "%s" (R$ %s/dia) passaria do teto em vigor (R$ %s comprometidos de R$ %s).',
                $a['nome'], $this->rs($extra), $this->rs($foto['comprometido']), $this->rs($this->cfg->tetoVigente())));

            return;
        }

        if (! $this->simular) {
            $this->religarSozinho($a);
            if ($auto = MarketingAutoAd::where('ad_id', $adId)->first()) {
                // Volta com idade zerada: a vida útil conta do dia em que voltou a entregar.
                $auto->update(['status' => MarketingAutoAd::ATIVO, 'launched_at' => now(), 'paused_at' => null, 'pause_reason' => null]);
            }
        }
        $foto['comprometido'] += $extra;
        $this->acao('religar', $adId, "Religuei \"{$a['nome']}\": {$motivo}", ['orcamento' => $a['orcamento']]);
    }

    /**
     * Liga o anúncio e os pais dele SEM ressuscitar os irmãos: anúncio com status próprio
     * ACTIVE dentro de campanha pausada volta junto quando ela liga — e o irmão costuma ser
     * justamente o criativo ruim que motivou a pausa. Pausa esses antes, depois sobe de baixo
     * para cima.
     *
     * @param  array<string, mixed>  $alvo
     */
    private function religarSozinho(array $alvo): void
    {
        $campId = $alvo['campanha']['id'];
        $meu = [];
        foreach ($this->fb->anunciosDaConta() as $ad) {
            if ((string) $ad['id'] === $alvo['id']) {
                $meu = $ad;
            } elseif ((string) ($ad['campaign']['id'] ?? '') === $campId
                && ($ad['status'] ?? '') === 'ACTIVE' && ($ad['effective_status'] ?? '') !== 'ACTIVE') {
                $this->fb->definirStatus((string) $ad['id'], 'PAUSED');
            }
        }

        $this->fb->definirStatus($alvo['id'], 'ACTIVE');
        if (($meu['adset']['status'] ?? 'PAUSED') !== 'ACTIVE' && ! empty($meu['adset']['id'])) {
            $this->fb->definirStatus((string) $meu['adset']['id'], 'ACTIVE');
        }
        if (($meu['campaign']['status'] ?? 'PAUSED') !== 'ACTIVE' && $campId !== '') {
            $this->fb->definirStatus($campId, 'ACTIVE');
        }
    }

    private function acaoVerba(string $orcId, float $valor, string $motivo, $orcamentos, array &$foto): void
    {
        $o = $orcamentos[$orcId] ?? null;
        if (! $o) {
            $this->recusar("Verba de {$orcId}: não é um orçamento diário desta conta.");

            return;
        }
        if (! $this->cfg->manage_whole_account && ! $this->orcamentoDoPiloto($o)) {
            $this->recusar("Verba de \"{$o['nome']}\": não foi criado pelo piloto (o gestor está limitado aos anúncios dele).");

            return;
        }
        if ($this->mexidoHa($orcId)) {
            $this->recusar("Verba de \"{$o['nome']}\": já mexida nas últimas 24h.");

            return;
        }

        $atual = (float) $o['diario'];
        $novo = round(max(self::VERBA_MINIMA, min($valor, $atual * self::PASSO_VERBA)), 2);
        $novo = max($novo, round($atual / self::PASSO_VERBA, 2));
        if (abs($novo - $atual) < 0.5) {
            return;
        }
        $delta = $o['entregando'] ? $novo - $atual : 0;
        if ($delta > 0 && $this->cfg->tetoVigente() < $foto['comprometido'] + $delta) {
            $this->recusar(sprintf('Verba de "%s" para R$ %s passaria do teto em vigor (R$ %s).', $o['nome'], $this->rs($novo), $this->rs($this->cfg->tetoVigente())));

            return;
        }

        if (! $this->simular) {
            $this->fb->definirOrcamento($orcId, $novo);
            MarketingAutoAd::whereIn('ad_id', $o['anuncios'])->update(['daily_budget' => $novo]);
        }
        $foto['comprometido'] += $delta;
        $ajuste = abs($valor - $novo) >= 0.5 ? " (pediu R$ {$this->rs($valor)}; limitei ao passo máximo)" : '';
        $this->acao('verba', $orcId, sprintf('Verba de "%s": R$ %s → R$ %s/dia%s. %s', $o['nome'], $this->rs($atual), $this->rs($novo), $ajuste, $motivo),
            ['de' => $atual, 'para' => $novo]);
    }

    /** @param array<string, mixed> $acao */
    private function acaoTestarPublico(array $acao, string $motivo): void
    {
        $jaHoje = MarketingAutopilotEvent::where('kind', 'acao')->where('simulated', $this->simular)
            ->where('data->acao', 'testar_publico')->where('created_at', '>=', now()->startOfDay())->exists();
        if ($jaHoje) {
            $this->recusar('Teste de público: já abri um hoje.');

            return;
        }
        $c = MarketingCreative::where('number', (int) ($acao['criativo'] ?? 0))->first();
        $mensagem = trim((string) ($acao['mensagem'] ?? ''));
        if (! $c || $mensagem === '') {
            $this->recusar('Teste de público: falta criativo válido ou o texto do anúncio.');

            return;
        }

        $publico = $this->publicoSeguro((array) ($acao['publico'] ?? []));
        $resumo = $this->resumoPublico($publico);
        if ($this->simular) {
            $this->acao('testar_publico', 'criativo-'.$c->number, "Testaria o {$c->name} em {$resumo}: {$motivo}", ['publico' => $publico]);

            return;
        }

        $ad = $this->piloto->publicar($c, ['mensagem' => $mensagem, 'titulo' => $acao['titulo'] ?? null], "teste {$resumo}", $publico);
        if ($ad) {
            $this->acao('testar_publico', (string) $ad->ad_id, "Abri teste de público: {$c->name} em {$resumo}. {$motivo}", ['publico' => $publico]);
        }
    }

    /**
     * Só os campos de público que a IA entende sem precisar de id da Meta, em cima do padrão
     * (país, tipo de localização, sem Advantage+). Interesse exige id que ela não tem: pedido
     * de interesse viraria público inválido ou, pior, aberto demais.
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

    /** @param array<string, mixed> $m */
    private function memorizar(array $m): void
    {
        $titulo = Str::limit(trim((string) ($m['titulo'] ?? '')), 180, '');
        $conteudo = trim((string) ($m['conteudo'] ?? ''));
        if ($titulo === '' || $conteudo === '' || $this->simular || MarketingMemory::where('titulo', $titulo)->exists()) {
            return;
        }
        MarketingMemory::create([
            'categoria' => in_array($m['categoria'] ?? '', MarketingMemory::CATEGORIAS, true) ? $m['categoria'] : 'aprendizado',
            'titulo' => $titulo,
            'conteudo' => $conteudo.' (anotado pelo gestor automático)',
            'periodo' => now()->format('d/m/Y'),
            // O que ela chama de "medido" ainda é leitura dela: só gente promove a fato.
            'confianca' => 'hipotese',
        ]);
        $this->registrar('memoria', "Anotei na memória de marketing: {$titulo}.");
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
    private function podeMexer(array $a): bool
    {
        if ($this->cfg->manage_whole_account || $a['do_piloto']) {
            return true;
        }
        $this->recusar("\"{$a['nome']}\" não foi criado pelo piloto (o gestor está limitado aos anúncios dele).");

        return false;
    }

    /** @param array<string, mixed> $o */
    private function orcamentoDoPiloto(array $o): bool
    {
        return MarketingAutoAd::whereIn('ad_id', $o['anuncios'])->exists();
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
    private function historico(): array
    {
        return MarketingAutopilotEvent::where('simulated', false)
            ->whereIn('kind', ['acao', 'recusou', 'pausou', 'publicou', 'alerta'])
            ->where('created_at', '>=', now()->subHours(72))
            ->orderByDesc('id')->limit(40)->get()->reverse()
            ->map(fn ($e) => $e->created_at->format('d/m H:i').' ['.$e->kind.'] '.Str::limit($e->message, 220))
            ->values()->all();
    }

    /** Falha repetida vira aviso: uma isolada é ruído (a Meta oscila), duas seguidas não. */
    private function falhou(string $tipo, string $texto): void
    {
        $anteriores = MarketingAutopilotEvent::where('kind', 'erro')->where('simulated', $this->simular)
            ->where('created_at', '>=', now()->subHours(3))->count();
        if ($anteriores >= 2) {
            $this->avisos->enviar('falha-'.$tipo, $texto, 6);
        }
    }

    /** @param array<string, mixed> $dados */
    private function acao(string $acao, string $objeto, string $msg, array $dados = []): void
    {
        $this->registrar('acao', $msg, ['acao' => $acao, 'objeto' => $objeto] + $dados);
    }

    private function recusar(string $msg): void
    {
        $this->registrar('recusou', $msg);
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
        $this->relato[] = ['tipo' => $tipo, 'msg' => $msg];
        MarketingAutopilotEvent::create([
            'kind' => $tipo,
            'message' => $msg,
            'data' => $dados ?: null,
            'simulated' => $this->simular,
        ]);
    }
}
