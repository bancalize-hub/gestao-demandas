<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\MarketingCreative;
use App\Models\MarketingCredential;
use App\Models\MarketingMemory;
use App\Support\Amostra;
use App\Support\Ddd;
use App\Support\FacebookAds;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /**
     * Página de otimização: onde estão os leads que QUALIFICAM, por recorte.
     *
     * Três regras que a tela inteira obedece, todas aprendidas errando nesta conta:
     *
     * 1. TODA taxa vem com intervalo de confiança e tamanho de amostra. "Sudeste 25% x
     *    Nordeste 11%" parecia decisão de verba pronta e era ruído — os intervalos se
     *    sobrepunham tanto que nem o sinal da diferença dava para afirmar.
     *
     * 2. LEAD SEM TRIAGEM não vira zero. Ele sai da taxa e vira faixa (limites de Manski):
     *    com 43% da base sem triagem, a taxa "real" de uma linha pode estar em qualquer
     *    ponto entre contar todos como ruins e contar todos como bons. Tratar não-triado
     *    como não-qualificado inventa precisão que não existe.
     *
     * 3. O QUE VEM DO FACEBOOK É SEPARADO DO QUE VEM DO CRM. O breakdown demográfico da
     *    Meta só sabe "conversa iniciada" — não existe qualificação nesse dado. Misturar
     *    as duas fontes na mesma tabela faria ler custo por conversa como se fosse custo
     *    por lead bom, que é exatamente a inversão que já se mediu nesta conta.
     */
    public function otimizacao(Request $request)
    {
        [$desde, $ateData] = self::datasCustomizadas($request);
        $periodo = (string) $request->query('periodo', 'last_30d');
        [$de, $ate] = self::janela($periodo, $desde, $ateData);

        // "Respondeu" = 2+ mensagens de entrada. A primeira é automática (o clique no
        // anúncio já dispara o texto pré-preenchido), então 1 mensagem não prova interesse.
        $respondeu = '(SELECT COUNT(*) FROM messages m WHERE m.conversation_id = conversations.id AND m.is_out = 0) >= 2';
        $reunioes = '(SELECT COUNT(*) FROM meetings mt WHERE mt.conversation_id = conversations.id)';
        $realizadas = '(SELECT COUNT(*) FROM meetings mt WHERE mt.conversation_id = conversations.id AND mt.attended = 1)';
        $adId = "JSON_UNQUOTE(JSON_EXTRACT(conversations.custom_fields, '$.anuncio.id'))";

        $leads = Conversation::selectRaw("
            conversations.id, conversations.phone, conversations.qualified,
            conversations.created_at, conversations.stage,
            {$respondeu} as respondeu,
            {$reunioes} as reunioes, {$realizadas} as realizadas,
            {$adId} as ad_id
        ")
            ->where('conversations.created_at', '>=', $de)
            ->where('conversations.created_at', '<', $ate)
            ->get();

        // Nomes de anúncio e campanha para os recortes de mídia. Ficam no mesmo cache dos
        // breakdowns: são a mesma viagem à Graph API.
        $r = self::ultimoBom("otimizacao-fb:{$periodo}:{$desde}:{$ateData}", function () use ($periodo, $desde, $ateData) {
            $fb = FacebookAds::make();

            $anuncios = [];
            foreach ($fb->listarAnuncios() as $a) {
                if (! empty($a['id'])) {
                    $anuncios[(string) $a['id']] = ['nome' => (string) ($a['name'] ?? ''), 'campanha' => (string) ($a['campaign_id'] ?? '')];
                }
            }
            $campanhas = [];
            foreach ($fb->listarCampanhas(100) as $c) {
                if (! empty($c['id'])) {
                    $campanhas[(string) $c['id']] = (string) ($c['name'] ?? '');
                }
            }

            return [
                'anuncios' => $anuncios,
                'campanhas' => $campanhas,
                'breakdowns' => [
                    ['chave' => 'idade_genero', 'titulo' => 'Idade e gênero', 'linhas' => $fb->breakdown('age,gender', $periodo, $desde, $ateData)],
                    ['chave' => 'posicionamento', 'titulo' => 'Posicionamento', 'linhas' => $fb->breakdown('publisher_platform,platform_position', $periodo, $desde, $ateData)],
                    ['chave' => 'dispositivo', 'titulo' => 'Dispositivo', 'linhas' => $fb->breakdown('impression_device', $periodo, $desde, $ateData)],
                    ['chave' => 'regiao_fb', 'titulo' => 'Região (segundo o Facebook)', 'linhas' => $fb->breakdown('region', $periodo, $desde, $ateData)],
                ],
            ];
        });

        $anuncios = $r['dados']['anuncios'] ?? [];
        $campanhas = $r['dados']['campanhas'] ?? [];

        $cortes = [
            $this->corte($leads, fn ($l) => $l->ad_id ? ($campanhas[$anuncios[$l->ad_id]['campanha'] ?? ''] ?? null) : null, 'Campanha',
                'O funil inteiro por campanha, do lead à venda. Vem do ad_id que o WhatsApp manda no referral do anúncio — lead orgânico não entra aqui.'),
            $this->corte($leads, fn ($l) => $l->ad_id ? ($anuncios[$l->ad_id]['nome'] ?? null) : null, 'Anúncio',
                'Recorte mais fino que campanha: mesma verba, peças diferentes. Amostra por linha cai na mesma proporção.'),
            $this->corte($leads, fn ($l) => Ddd::regiao($l->phone), 'Região',
                'Calculada pelo DDD do telefone — o Facebook não devolve qualificação por região, então esta é a única forma de cruzar origem com lead bom.'),
            $this->corte($leads, fn ($l) => Ddd::uf($l->phone), 'Estado (UF)',
                'Mesmo DDD, recorte mais fino. Quanto mais fino o corte, menor a amostra por linha — e mais fácil confundir ruído com sinal.'),
            $this->corte($leads, fn ($l) => $l->created_at?->locale('pt_BR')->isoFormat('dddd'), 'Dia da semana em que o lead chegou',
                'Serve para decidir em que dia o orçamento trabalha mais — este recorte é acionável no gerenciador, ao contrário do DDD.'),
            $this->corte($leads, fn ($l) => self::faixaHoraria($l->created_at?->hour), 'Faixa horária em que o lead chegou',
                'Também acionável: o Facebook permite programar a veiculação por horário.'),
        ];

        $triados = $leads->whereNotNull('qualified')->count();

        return response()->json([
            'base' => [
                'leads' => $leads->count(),
                'de_anuncio' => $leads->whereNotNull('ad_id')->count(),
                'triados' => $triados,
                'sem_triagem' => $leads->count() - $triados,
                'qualificados' => $leads->where('qualified', true)->count(),
                'reunioes' => $leads->sum('reunioes'),
                'realizadas' => $leads->sum('realizadas'),
                'vendas' => $leads->where('stage', 'fechado')->count(),
                'com_ddd' => $leads->filter(fn ($l) => Ddd::regiao($l->phone) !== null)->count(),
            ],
            'cortes' => $cortes,
            'facebook' => $r['dados']['breakdowns'] ?? [],
            'erro_facebook' => $r['erro'],
            'facebook_de' => $r['de'],
            'periodo' => ['de' => $de->toDateTimeString(), 'ate' => $ate->toDateTimeString()],
        ]);
    }

    private static function faixaHoraria(?int $hora): ?string
    {
        if ($hora === null) {
            return null;
        }

        return match (true) {
            $hora < 6 => 'Madrugada (0h–5h)',
            $hora < 12 => 'Manhã (6h–11h)',
            $hora < 18 => 'Tarde (12h–17h)',
            default => 'Noite (18h–23h)',
        };
    }

    /**
     * Um recorte da base: as linhas com taxa, intervalo e o veredito de se dá para
     * concluir alguma coisa com o que existe.
     *
     * @param  Collection<int, Conversation>  $leads
     * @param  callable(Conversation): ?string  $chave
     */
    private function corte($leads, callable $chave, string $titulo, string $nota): array
    {
        $grupos = [];
        $semChave = 0;

        foreach ($leads as $l) {
            $k = $chave($l);
            if ($k === null || $k === '') {
                $semChave++;

                continue;
            }
            $grupos[$k] ??= ['valor' => $k, 'leads' => 0, 'responderam' => 0, 'triados' => 0, 'qualificados' => 0,
                'marcaram' => 0, 'compareceram' => 0, 'vendas' => 0, 'reunioes' => 0, 'realizadas' => 0];
            $grupos[$k]['leads']++;
            if ($l->respondeu) {
                $grupos[$k]['responderam']++;
            }
            if ($l->qualified !== null) {
                $grupos[$k]['triados']++;
                if ($l->qualified) {
                    $grupos[$k]['qualificados']++;
                }
            }
            // O funil conta PESSOAS, não reuniões: um lead que remarcou três vezes é um
            // lead que marcou, não três. As duas contagens viajam juntas porque respondem
            // perguntas diferentes — "quantos chegaram até aqui" e "quanto de agenda isso deu".
            if ((int) $l->reunioes > 0) {
                $grupos[$k]['marcaram']++;
            }
            if ((int) $l->realizadas > 0) {
                $grupos[$k]['compareceram']++;
            }
            $grupos[$k]['reunioes'] += (int) $l->reunioes;
            $grupos[$k]['realizadas'] += (int) $l->realizadas;
            if ($l->stage === 'fechado') {
                $grupos[$k]['vendas']++;
            }
        }

        $linhas = [];
        foreach ($grupos as $g) {
            $g['sem_triagem'] = $g['leads'] - $g['triados'];
            $g['taxa'] = $g['triados'] > 0 ? round($g['qualificados'] / $g['triados'], 4) : null;
            $ic = Amostra::wilson($g['qualificados'], $g['triados']);
            $g['ic'] = [round($ic[0], 4), round($ic[1], 4)];
            // Limites de Manski: o pior e o melhor caso possíveis para o lead sem triagem.
            // Quando essa faixa é larga, ela — e não o intervalo estatístico — é o que
            // manda na conclusão.
            $g['manski'] = $g['leads'] > 0
                ? [round($g['qualificados'] / $g['leads'], 4), round(($g['qualificados'] + $g['sem_triagem']) / $g['leads'], 4)]
                : [0, 1];

            // Cada passo do funil sobre o passo ANTERIOR, não sobre o total. É o que
            // responde "onde este segmento vaza": 60% de leads que respondem e 5% de quem
            // responde que qualifica é um problema diferente do inverso, e as duas leituras
            // dariam a mesma porcentagem sobre o total.
            $taxa = fn (int $parte, int $todo) => $todo > 0 ? round($parte / $todo, 4) : null;
            $g['funil'] = [
                'respondeu' => $taxa($g['responderam'], $g['leads']),
                'qualificou' => $taxa($g['qualificados'], $g['triados']),
                'marcou' => $taxa($g['marcaram'], $g['qualificados']),
                'compareceu' => $taxa($g['compareceram'], $g['marcaram']),
                'fechou' => $taxa($g['vendas'], $g['compareceram']),
            ];

            $linhas[] = $g;
        }

        // Ordena pela taxa, mas a ordem é só de leitura — a conclusão é do veredito.
        usort($linhas, fn ($a, $b) => ($b['taxa'] ?? -1) <=> ($a['taxa'] ?? -1));

        return [
            'titulo' => $titulo,
            'nota' => $nota,
            'sem_chave' => $semChave,
            'linhas' => $linhas,
        ] + $this->veredito($linhas);
    }

    /** Abaixo disto a linha não entra em comparação nenhuma — é anedota, não amostra. */
    private const MIN_TRIADOS = 12;

    private static function plural(int $n, string $um, string $varios): string
    {
        return $n === 1 ? $um : sprintf($varios, $n);
    }

    /**
     * Dá ou não dá para concluir alguma coisa deste recorte.
     *
     * TRÊS GUARDAS, e a conclusão precisa passar pelas três. Cada uma existe porque a
     * ausência dela já produziu uma conclusão falsa nesta conta:
     *
     * 1. AMOSTRA MÍNIMA por linha. Sem isso, "ES: 100% de qualificação" (1 lead triado)
     *    lidera a tabela e vira decisão de verba.
     *
     * 2. CORREÇÃO PARA MÚLTIPLAS COMPARAÇÕES. Numa tabela de 27 estados há 351 pares;
     *    varrer todos atrás do mais distante encontra "diferença real" em dado aleatório
     *    quase sempre. O z sobe com o número de pares.
     *
     * 3. LIMITES DE MANSKI. Com 61% da base sem triagem, a taxa observada é de quem foi
     *    triado, não da linha. Se o pior caso de uma linha alcança o melhor caso da outra,
     *    a diferença pode ser inteiramente efeito de QUEM foi triado — e aí o que decide
     *    não é mais dado, é o viés de quem escolheu triar.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return array{conclusivo: bool, explicacao: string, faltam: ?int}
     */
    private function veredito(array $linhas): array
    {
        $comparaveis = array_values(array_filter($linhas, fn ($l) => $l['triados'] >= self::MIN_TRIADOS));

        if (count($comparaveis) < 2) {
            $faltam = self::MIN_TRIADOS;

            return [
                'conclusivo' => false,
                'explicacao' => 'Menos de duas linhas com pelo menos '.self::MIN_TRIADOS.' leads triados — não há o que comparar ainda. '
                    .'Linha com 2 ou 3 leads não vira porcentagem: 100% de 2 leads e 50% de 2 leads são o mesmo nada.',
                'faltam' => $faltam,
            ];
        }

        $pares = count($comparaveis) * (count($comparaveis) - 1) / 2;
        $z = Amostra::zCorrigido((int) $pares);

        foreach ($comparaveis as $i => $a) {
            foreach (array_slice($comparaveis, $i + 1) as $b) {
                $icA = Amostra::wilson($a['qualificados'], $a['triados'], $z);
                $icB = Amostra::wilson($b['qualificados'], $b['triados'], $z);

                if (Amostra::distinguiveis($icA, $icB) && Amostra::distinguiveis($a['manski'], $b['manski'])) {
                    return [
                        'conclusivo' => true,
                        'explicacao' => "{$a['valor']} e {$b['valor']} se separam mesmo depois de corrigir para ".self::plural((int) $pares, 'a comparação', 'as %d comparações').' da tabela '
                            .'E mesmo no pior cenário dos leads sem triagem. Essa diferença é real.',
                        'faltam' => null,
                    ];
                }
            }
        }

        $melhor = $comparaveis[0];
        $pior = end($comparaveis);
        $faltam = Amostra::amostraNecessaria((float) $melhor['taxa'], (float) $pior['taxa']);

        return [
            'conclusivo' => false,
            'explicacao' => 'A ordem desta tabela ainda é ruído: nenhuma dupla se separa depois de corrigir para '
                .self::plural((int) $pares, 'a comparação', 'as %d comparações').' e para os leads sem triagem. '
                .($faltam ? "Para uma diferença do tamanho da que aparece aqui ({$melhor['valor']} × {$pior['valor']}) seriam necessários ~{$faltam} leads TRIADOS por linha."
                    : 'As taxas são praticamente iguais — não há diferença para enxergar.'),
            'faltam' => $faltam,
        ];
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
