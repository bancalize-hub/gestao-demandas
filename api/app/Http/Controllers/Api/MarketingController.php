<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MarketingCreative;
use App\Models\MarketingCredential;
use App\Models\MarketingMemory;
use App\Support\FacebookAds;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Módulo de marketing: credencial do Facebook Ads e biblioteca de criativos.
 * O terminal do agente reusa AgentController (sessões com kind=marketing).
 */
class MarketingController extends Controller
{
    /** Estado da configuração — nunca devolve os segredos, só se estão preenchidos. */
    public function status()
    {
        $c = MarketingCredential::atual();

        return response()->json([
            'app_id' => $c->app_id,
            'ad_account_id' => $c->ad_account_id,
            'page_id' => $c->page_id,
            'dataset_id' => $c->dataset_id,
            'capi_test_code' => $c->capi_test_code,
            'graph_version' => $c->graph_version,
            'tem_token' => filled($c->access_token),
            'tem_app_secret' => filled($c->app_secret),
            'checked_at' => $c->checked_at,
            'last_error' => $c->last_error,
        ]);
    }

    /** Salva a configuração. Campo de segredo em branco = manter o que já está lá. */
    public function salvar(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'nullable|string|max:64',
            'app_secret' => 'nullable|string|max:255',
            'access_token' => 'nullable|string|max:1000',
            'ad_account_id' => 'nullable|string|max:64',
            'page_id' => 'nullable|string|max:64',
            'dataset_id' => 'nullable|string|max:64',
            'capi_test_code' => 'nullable|string|max:64',
            'graph_version' => 'nullable|string|max:10',
        ]);

        $c = MarketingCredential::atual();
        foreach (['app_id', 'ad_account_id', 'page_id', 'dataset_id', 'capi_test_code', 'graph_version'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $c->$campo = $data[$campo] ?: null;
            }
        }
        // Segredo só é sobrescrito quando vem preenchido — assim a tela pode salvar
        // "o resto" sem obrigar a recolar o token toda vez.
        foreach (['app_secret', 'access_token'] as $segredo) {
            if (filled($data[$segredo] ?? null)) {
                $c->$segredo = $data[$segredo];
            }
        }
        $c->graph_version = $c->graph_version ?: 'v23.0';
        $c->save();

        return response()->json(['ok' => true] + FacebookAds::make()->testar());
    }

    /** Testa a credencial de verdade (uma chamada mínima à Graph API). */
    public function testar()
    {
        return response()->json(FacebookAds::make()->testar());
    }

    public function criativos()
    {
        return MarketingCreative::orderBy('number')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'number' => $c->number,
            'original_name' => $c->original_name,
            'mime' => $c->mime,
            'size' => $c->size,
            'notes' => $c->notes,
            'status' => $c->status ?: MarketingCreative::TESTANDO,
            'status_note' => $c->status_note,
            'status_at' => $c->status_at,
            'no_facebook' => (bool) $c->fb_image_hash,
            'url' => "/api/marketing/creatives/{$c->id}/arquivo",
            'created_at' => $c->created_at,
        ]);
    }

    /**
     * Desempenho real de cada criativo no período: gasto na Meta + funil do CRM.
     *
     * O caminho é criativo → image_hash → anúncios → (gasto por ad_id na Meta, leads por
     * ad_id no CRM). O ad_id é a única chave que os dois lados conhecem: a Meta não sabe
     * o que é "Criativo 3" e o CRM não vê hash de imagem.
     *
     * Endpoint separado do {@see criativos()} de propósito: a biblioteca tem que abrir
     * na hora, e isto aqui depende de duas chamadas à Graph API que podem demorar ou
     * falhar. A tela mostra os cards primeiro e preenche os números quando chegam.
     */
    public function desempenhoCriativos(Request $request)
    {
        [$desde, $ateData] = self::datasCustomizadas($request);
        $periodo = (string) $request->query('periodo', 'last_30d');
        [$de, $ate] = self::janela($periodo, $desde, $ateData);

        // Mesma atribuição do painel de campanhas: o ad_id que veio no referral do
        // WhatsApp. Criativo que rodou em anúncio sem esse referral fica sem leads.
        $adId = "JSON_UNQUOTE(JSON_EXTRACT(conversations.custom_fields, '$.anuncio.id'))";
        $statsPorAd = $this->statsPorChave($adId, $de, $ate);

        $r = self::ultimoBom("criativos-desempenho:{$periodo}:{$desde}:{$ateData}", function () use ($periodo, $desde, $ateData) {
            $fb = FacebookAds::make();

            return [
                'por_imagem' => $fb->anunciosPorImagem(),
                'gastos' => $fb->gastoPorAnuncio($periodo, $desde, $ateData),
            ];
        });

        $porImagem = $r['dados']['por_imagem'] ?? [];
        $gastos = $r['dados']['gastos'] ?? [];

        $linhas = [];
        foreach (MarketingCreative::orderBy('number')->get() as $c) {
            // Sem hash o criativo nunca subiu para a Meta — nunca rodou, então não é
            // "gastou zero", é "não tem o que medir". A tela diz uma coisa e não a outra.
            $anuncios = filled($c->fb_image_hash) ? ($porImagem[$c->fb_image_hash] ?? []) : [];

            $linha = ['id' => $c->id, 'anuncios' => count($anuncios), 'ativos' => 0,
                'gasto' => 0.0, 'impressoes' => 0, 'cliques' => 0] + self::ZERADO;

            foreach ($anuncios as $ad) {
                if ($ad['ativo']) {
                    $linha['ativos']++;
                }
                if ($g = $gastos[$ad['id']] ?? null) {
                    $linha['gasto'] += $g['gasto'];
                    $linha['impressoes'] += $g['impressoes'];
                    $linha['cliques'] += $g['cliques'];
                }
                if ($s = $statsPorAd[$ad['id']] ?? null) {
                    foreach (self::ZERADO as $campo => $_) {
                        $linha[$campo] += $s[$campo];
                    }
                }
            }

            $linha['gasto'] = round($linha['gasto'], 2);
            // Custo só existe quando houve gasto E resultado. Dividir por zero devolveria
            // "R$ 0,00 por lead" no criativo que não trouxe lead nenhum — o pior número
            // possível parecendo o melhor da tela.
            $linha['cpl'] = $linha['leads'] > 0 && $linha['gasto'] > 0 ? round($linha['gasto'] / $linha['leads'], 2) : null;
            $linha['cpq'] = $linha['qualificados'] > 0 && $linha['gasto'] > 0 ? round($linha['gasto'] / $linha['qualificados'], 2) : null;

            $linhas[] = $linha;
        }

        return response()->json([
            'desempenho' => $linhas,
            'erro' => $r['erro'],
            'de' => $r['de'],
            'periodo' => ['de' => $de->toDateTimeString(), 'ate' => $ate->toDateTimeString()],
        ]);
    }

    /**
     * Sobe um criativo. O nome é gerado (Criativo 1, 2, 3…) porque é assim que o
     * usuário vai se referir a ele conversando com o agente.
     */
    public function subirCriativo(Request $request)
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:30720', // 30MB
            'notes' => 'nullable|string|max:500',
        ]);

        $file = $request->file('file');
        $path = $file->store('marketing/creatives');

        // Lock para dois uploads simultâneos não virarem dois "Criativo 4".
        $criativo = DB::transaction(function () use ($file, $path, $data) {
            $n = MarketingCreative::lockForUpdate()->max('number') + 1;

            return MarketingCreative::create([
                'name' => "Criativo {$n}",
                'number' => $n,
                'original_name' => $file->getClientOriginalName() ?: basename($path),
                'path' => $path,
                'mime' => $file->getMimeType() ?: 'image/jpeg',
                'size' => (int) $file->getSize(),
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return response()->json($criativo, 201);
    }

    public function atualizarCriativo(Request $request, MarketingCreative $creative)
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
            'status' => ['nullable', 'string', Rule::in(MarketingCreative::STATUS)],
            'status_note' => 'nullable|string|max:500',
        ]);

        // Campo ausente é campo NÃO MEXIDO. Antes o `notes` era sempre reescrito com o
        // que viesse (ou null): salvar só o veredito apagaria a descrição que a IA lê.
        foreach (['notes', 'status_note'] as $campo) {
            if ($request->has($campo)) {
                $creative->$campo = $data[$campo] ?: null;
            }
        }

        if (filled($data['status'] ?? null) && $data['status'] !== $creative->status) {
            $creative->status = $data['status'];
            $creative->status_at = now();
            $creative->status_by = $request->user()?->getKey();
        }

        $creative->save();

        return response()->json($creative);
    }

    public function apagarCriativo(MarketingCreative $creative)
    {
        Storage::delete($creative->path);
        $creative->delete();

        return response()->json(['ok' => true]);
    }

    /** Serve a imagem para a miniatura da tela. */
    public function arquivo(MarketingCreative $creative)
    {
        abort_unless(Storage::exists($creative->path), 404);

        return response(Storage::get($creative->path), 200, [
            'Content-Type' => $creative->mime ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Último resultado BOM de uma chamada à Graph API, guardado por 6 h.
     *
     * A Graph API falha sozinha — limite de requisições (code 17), token expirado, um 500
     * do lado deles. Sem isto, um tropeço de 30 segundos esvaziava o painel inteiro: a
     * lista de campanhas subia como exceção, o front caía no `catch` e zerava a tabela
     * SEM dizer por quê. Melhor mostrar o número de minutos atrás, avisando, do que "—".
     */
    private static function ultimoBom(string $nome, callable $buscar): array
    {
        $chave = "fb_ok:{$nome}:".MarketingCredential::atual()->getKey();

        try {
            $dados = $buscar();
            Cache::put($chave, ['dados' => $dados, 'em' => now()->toIso8601String()], now()->addHours(6));

            return ['dados' => $dados, 'erro' => null, 'de' => null];
        } catch (\Throwable $e) {
            $cache = Cache::get($chave);

            return [
                'dados' => $cache['dados'] ?? [],
                'erro' => $e->getMessage(),
                'de' => $cache['em'] ?? null,
            ];
        }
    }

    /** Lista campanhas do Facebook Ads com orçamento e status. */
    public function campanhas(Request $request)
    {
        $limite = $request->integer('limite', 50);

        // Miniatura vem junto e DENTRO do mesmo ultimoBom: são duas chamadas à Graph API
        // que a tabela usa na mesma linha. Separadas, um tropeço só na busca das imagens
        // deixaria a tabela metade nova e metade do cache de horas atrás.
        $r = self::ultimoBom('campanhas', function () use ($limite) {
            $fb = FacebookAds::make();
            $campanhas = $fb->listarCampanhas($limite);
            $minis = $fb->miniaturasPorCampanha();
            $orcs = $fb->orcamentosDeConjunto();

            return array_map(function (array $c) use ($minis, $orcs) {
                $id = (string) ($c['id'] ?? '');
                $doConjunto = $orcs[$id] ?? null;

                // O orçamento EFETIVO da campanha, venha de onde vier: a campanha (CBO) ou
                // a soma dos conjuntos (ABO). O painel mostra um número só porque é um
                // número só que sai da conta no fim do dia.
                $naCampanha = (int) ($c['daily_budget'] ?? 0);

                return $c + [
                    'miniatura' => $minis[$id] ?? null,
                    'orcamento_diario' => $naCampanha ?: ($doConjunto['diario'] ?? 0) ?: null,
                    'orcamento_nivel' => $naCampanha ? 'campanha' : ($doConjunto ? 'conjunto' : null),
                    // Quantos conjuntos disputam esse orçamento — 1 dá para editar daqui.
                    'orcamento_conjuntos' => $doConjunto['conjuntos'] ?? 0,
                ];
            }, $campanhas);
        });

        return response()->json(['campanhas' => $r['dados'], 'erro' => $r['erro'], 'de' => $r['de']]);
    }

    /** Insights: impressões, cliques, gasto, CPM, CPC, ações por campanha/conjunto/anúncio. */
    public function metricasFb(Request $request)
    {
        $nivel = $request->get('nivel', 'campaign');
        $periodo = $request->get('periodo', 'last_7d');
        $id = $request->get('id');
        [$desde, $ateData] = self::datasCustomizadas($request);

        $chave = "metricas:{$nivel}:{$periodo}:{$id}:{$desde}:{$ateData}";
        $r = self::ultimoBom($chave, fn () => FacebookAds::make()->metricas($nivel, $periodo, $id, $desde, $ateData));

        return response()->json(['metricas' => $r['dados'], 'erro' => $r['erro'], 'de' => $r['de']]);
    }

    /**
     * O intervalo escolhido no calendário (`de`/`ate`, YYYY-MM-DD), quando houver.
     *
     * Datas invertidas são trocadas em vez de recusadas: quem clica primeiro no fim e
     * depois no início quer o mesmo intervalo, não um erro.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function datasCustomizadas(Request $request): array
    {
        $de = $request->query('de');
        $ate = $request->query('ate');
        $formato = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;

        $de = $formato($de);
        $ate = $formato($ate);
        if (! $de || ! $ate) {
            return [null, null];
        }

        return $de <= $ate ? [$de, $ate] : [$ate, $de];
    }

    /**
     * Métricas reais do CRM por campanha do Facebook Ads.
     *
     * Fonte primária: custom_fields->anuncio->id (ad_id do referral do WhatsApp).
     * O ad_id é cruzado com o mapeamento ad→campanha via FB API (cache de 10 min).
     * Fonte secundária (legado): custom_fields->criativo (texto "Criativo N").
     *
     * REUNIÃO VEM DA AGENDA, NÃO DA ETAPA. Contar `stage = 'agendamento'` dava zero em
     * toda campanha: na empresa 1 a etapa chamada "Reunião Agendada" tem a CHAVE
     * `proposta` (a chave `agendamento` é outra etapa, anterior). Chave de etapa é
     * texto livre por empresa — não serve de métrica. A tabela `meetings` é o registro
     * de verdade da reunião, tem data própria e não some quando o lead avança de etapa.
     *
     * O `periodo` é o MESMO date_preset usado no gasto: sem ele o CPL dividia o gasto
     * de 7/30 dias pelos leads de todos os tempos — número que não é de período nenhum.
     */
    public function adStats(Request $request)
    {
        [$desde, $ateData] = self::datasCustomizadas($request);
        [$de, $ate] = self::janela((string) $request->query('periodo', 'last_7d'), $desde, $ateData);

        $adId = "JSON_UNQUOTE(JSON_EXTRACT(conversations.custom_fields, '$.anuncio.id'))";
        $criativo = "JSON_UNQUOTE(JSON_EXTRACT(conversations.custom_fields, '$.criativo'))";

        // --- fonte primária: anuncio.id (referral do WhatsApp) -------------------
        $statsPorAdId = $this->statsPorChave($adId, $de, $ate);

        // Mapeamento ad_id → campaign_id via FB API (cacheado 10 min). A chave leva a
        // credencial: o mapa é da conta de anúncios DAQUELA empresa, e uma chave global
        // serviria os anúncios de uma empresa para a outra.
        //
        // SEM ESTE MAPA NÃO EXISTE LINHA NENHUMA: todo lead é descartado no `continue`
        // abaixo e o painel devolve `stats_campanha: []` — na tela, Leads/Reuniões/Vendas
        // zerados em TODAS as campanhas, como se o anúncio não tivesse dado resultado.
        // Por isso a falha sobe no `erro` em vez de ser engolida: já aconteceu de um
        // arquivo de cache escrito por outro usuário do sistema (artisan rodado como root)
        // derrubar a escrita do www-data e zerar o painel inteiro sem uma linha de log.
        $adCampMap = [];
        $erro = null;
        try {
            $cacheKey = 'fb_ad_camp_map:'.MarketingCredential::atual()->getKey();
            $adCampMap = Cache::remember($cacheKey, 600, function () {
                $map = [];
                foreach (FacebookAds::make()->listarAnuncios() as $ad) {
                    if (! empty($ad['id']) && ! empty($ad['campaign_id'])) {
                        $map[$ad['id']] = $ad['campaign_id'];
                    }
                }

                return $map;
            });
        } catch (\Throwable $e) {
            $erro = 'Não consegui cruzar os anúncios com as campanhas: '.$e->getMessage();
            report($e);
        }
        if (! $erro && ! $adCampMap && $statsPorAdId) {
            $erro = 'A conta de anúncios não devolveu nenhum anúncio — sem isso não dá para dizer de que campanha veio cada lead.';
        }

        // O lead que não casa com nenhuma campanha vai para um balde à parte, NUNCA para o
        // lixo. Somado à tabela, ele fecha a conta com o total de leads do CRM; descartado
        // em silêncio (como era antes), ele fazia o painel mentir por omissão.
        $porCampanha = [];
        $semAtribuicao = self::ZERADO;
        foreach ($statsPorAdId as $ad => $stat) {
            $campId = $adCampMap[$ad] ?? null;
            if (! $campId) {
                foreach (self::ZERADO as $campo => $_) {
                    $semAtribuicao[$campo] += $stat[$campo];
                }

                continue;
            }
            if (! isset($porCampanha[$campId])) {
                $porCampanha[$campId] = ['campaign_id' => $campId] + self::ZERADO;
            }
            foreach (self::ZERADO as $campo => $_) {
                $porCampanha[$campId][$campo] += $stat[$campo];
            }
        }

        // --- fonte secundária: criativo textual (legado) -------------------------
        $statsCriativo = [];
        foreach ($this->statsPorChave($criativo, $de, $ate) as $nome => $stat) {
            $statsCriativo[] = ['criativo' => $nome] + $stat;
        }

        return response()->json([
            'stats' => $statsCriativo,
            'stats_campanha' => array_values($porCampanha),
            'sem_atribuicao' => $semAtribuicao,
            'erro' => $erro,
            'periodo' => ['de' => $de->toDateTimeString(), 'ate' => $ate->toDateTimeString()],
        ]);
    }

    /** Formato de uma linha de stats — também serve de acumulador zerado. */
    private const ZERADO = ['leads' => 0, 'responderam' => 0, 'qualificados' => 0, 'reunioes' => 0, 'realizadas' => 0, 'vendas' => 0];

    /**
     * O funil do período agrupado por uma chave de atribuição (ad_id ou "Criativo N"),
     * lida do custom_fields da conversa.
     *
     * COORTE: uma janela só, a da CHEGADA DO LEAD. Toda coluna da linha fala das mesmas
     * pessoas — dos leads que entraram no período, quantos responderam, marcaram reunião,
     * compareceram e compraram. A reunião conta na janela em que o LEAD entrou, não na
     * data em que foi marcada.
     *
     * Já foi das duas maneiras: contar a reunião pela data em que ela foi marcada fazia
     * a linha exibir reunião com ZERO lead (o lead chegou 23h17 de ontem e marcou 00h22
     * de hoje) e, pior, a coluna ao lado dividia o gasto de HOJE por uma reunião que o
     * dinheiro de ONTEM produziu. Custo por reunião só significa alguma coisa quando o
     * numerador e o denominador vêm do mesmo dinheiro.
     *
     * "Realizada" é a reunião com presença confirmada (`attended`), apurada pela Meet
     * API — reunião futura ou no-show não entra.
     *
     * "Qualificado" é julgamento humano gravado em `conversations.qualified`, não regra
     * automática: só conta quem é 1. Lead ainda não triado (NULL) não infla nem afunda a
     * coluna — some dela, e é por isso que qualificados nunca passa de leads.
     *
     * @return array<string, array{leads:int, responderam:int, qualificados:int, reunioes:int, realizadas:int, vendas:int}>
     */
    private function statsPorChave(string $chave, Carbon $de, Carbon $ate): array
    {
        // "Respondeu" = mandou 2+ mensagens. A primeira é automática (o clique no anúncio
        // já dispara o texto pré-preenchido do "Enviar mensagem"), então 1 mensagem não
        // prova interesse nenhum — é a segunda que separa o curioso de quem quer conversar.
        $respondeu = '(SELECT COUNT(*) FROM messages m WHERE m.conversation_id = conversations.id AND m.is_out = 0) >= 2';
        // A reunião vem da AGENDA, não da etapa do funil (chave de etapa é texto livre por
        // empresa — foi o que zerava a coluna). Subconsulta em vez de join: com join, um
        // lead com duas reuniões contaria duas vezes no COUNT(*) dos leads.
        $reunioes = '(SELECT COUNT(*) FROM meetings mt WHERE mt.conversation_id = conversations.id)';
        $realizadas = '(SELECT COUNT(*) FROM meetings mt WHERE mt.conversation_id = conversations.id AND mt.attended = 1)';

        $linhas = [];

        $rows = Conversation::selectRaw("
            {$chave} as chave,
            COUNT(*) as leads,
            SUM({$respondeu}) as responderam,
            SUM(conversations.qualified = 1) as qualificados,
            SUM({$reunioes}) as reunioes,
            SUM({$realizadas}) as realizadas,
            SUM(conversations.stage = 'fechado') as vendas
        ")
            ->whereRaw("{$chave} IS NOT NULL")
            ->where('conversations.created_at', '>=', $de)
            ->where('conversations.created_at', '<', $ate)
            ->groupByRaw($chave)
            ->get();

        foreach ($rows as $r) {
            $linhas[$r->chave] = [
                'leads' => (int) $r->leads,
                'responderam' => (int) $r->responderam,
                'qualificados' => (int) $r->qualificados,
                'reunioes' => (int) $r->reunioes,
                'realizadas' => (int) $r->realizadas,
                'vendas' => (int) $r->vendas,
            ];
        }

        return $linhas;
    }

    /**
     * A janela de datas que corresponde ao date_preset da Meta, no fuso da conta
     * (America/Sao_Paulo dos dois lados). Verificado contra a Graph API: `last_7d` e
     * `last_30d` terminam ONTEM — não incluem hoje. `today` e `this_month` incluem.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function janela(string $periodo, ?string $desde = null, ?string $ateData = null): array
    {
        $hoje = now()->startOfDay();

        // Intervalo do calendário: as DUAS pontas entram (o dia final inteiro), por isso
        // o +1 dia no fim — a janela é [de 00:00, ate+1 00:00). É o mesmo intervalo que o
        // `time_range` da Graph API cobre, lá com o `until` inclusivo.
        if ($desde && $ateData) {
            return [Carbon::parse($desde)->startOfDay(), Carbon::parse($ateData)->startOfDay()->addDay()];
        }

        return match ($periodo) {
            'today' => [$hoje, $hoje->copy()->addDay()],
            // Ontem é o único período FECHADO da lista: o dia acabou, o gasto parou de
            // subir e todo lead que ia entrar já entrou. É a leitura que serve para
            // decidir verba — em "Hoje" o gasto corre na frente dos leads e o custo por
            // qualificado parece pior do que é até o dia virar.
            'yesterday' => [$hoje->copy()->subDay(), $hoje],
            'last_30d' => [$hoje->copy()->subDays(30), $hoje],
            'this_month' => [$hoje->copy()->startOfMonth(), $hoje->copy()->addDay()],
            default => [$hoje->copy()->subDays(7), $hoje], // last_7d
        };
    }

    /**
     * Atualiza status (ACTIVE|PAUSED) e/ou orçamento diário de uma campanha.
     *
     * Status e orçamento vão por caminhos diferentes de propósito: status é sempre da
     * campanha, orçamento pode morar nela ou no conjunto — quem resolve isso é o
     * {@see FacebookAds::atualizarOrcamentoDiario()}.
     */
    public function atualizarCampanha(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => 'nullable|string|in:ACTIVE,PAUSED',
            'orcamento_diario_reais' => 'nullable|numeric|min:1',
        ]);

        $fb = FacebookAds::make();
        $resultado = [];

        try {
            if (isset($data['status'])) {
                $resultado['status'] = $fb->atualizarCampanha($id, ['status' => $data['status']]);
            }
            if (isset($data['orcamento_diario_reais'])) {
                $resultado['orcamento'] = $fb->atualizarOrcamentoDiario($id, (float) $data['orcamento_diario_reais']);
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'erro' => $e->getMessage()], 422);
        }

        if ($resultado === []) {
            return response()->json(['ok' => false, 'erro' => 'Nenhum campo para atualizar.'], 422);
        }

        // A lista em cache tem o orçamento/status antigos; sem isto a tela recarregaria
        // e mostraria o valor de antes, parecendo que o salvamento não pegou.
        Cache::forget('fb_ok:campanhas:'.MarketingCredential::atual()->getKey());

        return response()->json(['ok' => true, 'resultado' => $resultado]);
    }

    // ---------------------------------------------------------------------------
    // Memória de marketing: o que já se aprendeu sobre os anúncios desta empresa.
    // Separada da memória da IA de vendas de propósito — ver MarketingMemory.
    // ---------------------------------------------------------------------------

    public function memorias()
    {
        return response()->json([
            'memorias' => MarketingMemory::orderByDesc('fixado')->orderByDesc('updated_at')->get(),
            'categorias' => MarketingMemory::CATEGORIAS,
        ]);
    }

    public function salvarMemoria(Request $request)
    {
        return response()->json(MarketingMemory::create($this->validarMemoria($request)), 201);
    }

    public function atualizarMemoria(Request $request, MarketingMemory $memoria)
    {
        $memoria->update($this->validarMemoria($request));

        return response()->json($memoria);
    }

    public function apagarMemoria(MarketingMemory $memoria)
    {
        $memoria->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validarMemoria(Request $request): array
    {
        return $request->validate([
            'categoria' => 'required|string|in:'.implode(',', MarketingMemory::CATEGORIAS),
            'titulo' => 'required|string|max:180',
            'conteudo' => 'required|string|max:4000',
            'periodo' => 'nullable|string|max:64',
            'confianca' => 'required|string|in:'.implode(',', MarketingMemory::CONFIANCAS),
            'fixado' => 'boolean',
        ]);
    }

    /** Duplica a campanha (conjuntos e anúncios juntos), pausada, como no Facebook. */
    public function duplicarCampanha(Request $request, string $id)
    {
        $sufixo = trim((string) $request->input('sufixo', '')) ?: ' (cópia)';

        try {
            $resultado = FacebookAds::make()->duplicarCampanha($id, $sufixo);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'erro' => $e->getMessage()], 422);
        }

        Cache::forget('fb_ok:campanhas:'.MarketingCredential::atual()->getKey());

        return response()->json(['ok' => true, 'resultado' => $resultado]);
    }
}
