<?php

namespace App\Support;

use App\Models\MarketingCreative;
use App\Models\MarketingCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Cliente da Graph API (Marketing API) do Facebook Ads.
 *
 * REGRA DE OURO desta classe: nada que ela cria entra no ar. Campanha, conjunto e
 * anúncio são SEMPRE criados com status PAUSED, e o status nunca vem de fora — quem
 * publica é o usuário, pela tela do Facebook. Um erro de interpretação do agente não
 * pode virar dinheiro gasto.
 */
class FacebookAds
{
    /** Status forçado em tudo que é criado. Não existe caminho que passe outro valor. */
    private const STATUS_CRIACAO = 'PAUSED';

    public function __construct(private MarketingCredential $cred) {}

    public static function make(): self
    {
        return new self(MarketingCredential::atual());
    }

    public function configurado(): bool
    {
        return filled($this->cred->access_token) && filled($this->cred->ad_account_id);
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/'.trim($this->cred->graph_version ?: 'v23.0');
    }

    /** `act_123…` — aceita o id com ou sem o prefixo, porque todo mundo digita dos dois jeitos. */
    private function conta(): string
    {
        $id = trim((string) $this->cred->ad_account_id);

        return str_starts_with($id, 'act_') ? $id : 'act_'.$id;
    }

    /**
     * Chamada crua. Erro da Graph API vem como 200-com-corpo-de-erro ou 4xx com JSON;
     * os dois viram exceção com a mensagem que o Facebook deu, que é o que o agente
     * precisa ler para se corrigir sozinho.
     */
    private function call(string $metodo, string $caminho, array $params = []): array
    {
        if (! $this->configurado()) {
            throw new RuntimeException('Facebook Ads não configurado: falta token de acesso ou ID da conta de anúncios.');
        }

        $params['access_token'] = $this->cred->access_token;
        $url = $this->base().'/'.ltrim($caminho, '/');

        $res = $metodo === 'GET'
            ? Http::timeout(60)->get($url, $params)
            : Http::timeout(60)->asForm()->post($url, $params);

        $json = $res->json();
        if (! is_array($json)) {
            throw new RuntimeException('Resposta inesperada do Facebook (HTTP '.$res->status().').');
        }
        if (isset($json['error'])) {
            $e = $json['error'];
            $msg = $e['error_user_msg'] ?? $e['message'] ?? 'erro desconhecido';
            $extra = isset($e['code']) ? " (code {$e['code']})" : '';
            throw new RuntimeException('Facebook recusou: '.$msg.$extra);
        }

        return $json;
    }

    /** Testa a credencial de verdade: quem é o token e a conta responde? */
    public function testar(): array
    {
        try {
            $me = $this->call('GET', '/me', ['fields' => 'id,name']);
            $conta = $this->call('GET', '/'.$this->conta(), [
                'fields' => 'name,account_status,currency,timezone_name,amount_spent',
            ]);
            $this->cred->forceFill(['checked_at' => now(), 'last_error' => null])->save();

            return [
                'ok' => true,
                'usuario' => $me['name'] ?? $me['id'] ?? '?',
                'conta' => $conta['name'] ?? $this->conta(),
                'moeda' => $conta['currency'] ?? null,
                'status_conta' => $conta['account_status'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->cred->forceFill(['checked_at' => now(), 'last_error' => $e->getMessage()])->save();

            return ['ok' => false, 'mensagem' => $e->getMessage()];
        }
    }

    public function resumoConta(): array
    {
        return $this->call('GET', '/'.$this->conta(), [
            'fields' => 'name,account_status,currency,timezone_name,amount_spent,balance',
        ]);
    }

    public function listarCampanhas(int $limite = 25): array
    {
        $r = $this->call('GET', '/'.$this->conta().'/campaigns', [
            'fields' => 'id,name,objective,status,effective_status,daily_budget,lifetime_budget,created_time',
            'limit' => max(1, min($limite, 100)),
        ]);

        return $r['data'] ?? [];
    }

    /**
     * Cria a campanha. `orcamento_diario_reais` vira centavos porque a Graph API
     * trabalha na menor unidade da moeda da conta.
     */
    public function criarCampanha(string $nome, string $objetivo, ?float $orcamentoDiarioReais = null): array
    {
        $params = [
            'name' => $nome,
            'objective' => $objetivo,
            'status' => self::STATUS_CRIACAO,
            'special_ad_categories' => '[]',
        ];
        if ($orcamentoDiarioReais !== null) {
            $params['daily_budget'] = (int) round($orcamentoDiarioReais * 100);
        }

        return $this->call('POST', '/'.$this->conta().'/campaigns', $params);
    }

    /**
     * @param  ?string  $destino  destination_type — WHATSAPP no clique-para-mensagem.
     *                            SEM ELE O CONJUNTO NÃO ENTREGA no WhatsApp: a Meta cria
     *                            o objeto sem reclamar e manda o tráfego para o destino
     *                            padrão, então o anúncio "existe" e não gera conversa
     *                            nenhuma. É o par obrigatório do `promoted_object` com a
     *                            página — é assim que os conjuntos que rodam nesta conta
     *                            estão montados.
     */
    public function criarConjunto(
        string $campanhaId,
        string $nome,
        ?float $orcamentoDiarioReais,
        string $otimizacao,
        array $segmentacao,
        ?string $billingEvent = null,
        ?string $destino = null,
        ?array $promotedObject = null,
    ): array {
        $params = [
            'name' => $nome,
            'campaign_id' => $campanhaId,
            'status' => self::STATUS_CRIACAO,
            'optimization_goal' => $otimizacao,
            'billing_event' => $billingEvent ?: 'IMPRESSIONS',
            'targeting' => json_encode($segmentacao ?: ['geo_locations' => ['countries' => ['BR']]]),
        ];
        if ($orcamentoDiarioReais !== null) {
            $params['daily_budget'] = (int) round($orcamentoDiarioReais * 100);
        }
        if ($destino) {
            $params['destination_type'] = $destino;
        }
        if ($promotedObject) {
            $params['promoted_object'] = json_encode($promotedObject);
        }

        return $this->call('POST', '/'.$this->conta().'/adsets', $params);
    }

    /**
     * Sobe a imagem do criativo e guarda o hash. O upload só acontece uma vez por
     * criativo — o Facebook devolve o mesmo hash para o mesmo arquivo, mas repetir
     * o upload a cada anúncio é desperdício de tempo e de banda.
     */
    public function garantirImagem(MarketingCreative $criativo): string
    {
        if (filled($criativo->fb_image_hash)) {
            return $criativo->fb_image_hash;
        }

        if (! Storage::exists($criativo->path)) {
            throw new RuntimeException("Arquivo do {$criativo->name} não está mais no servidor.");
        }

        $r = $this->call('POST', '/'.$this->conta().'/adimages', [
            'bytes' => base64_encode(Storage::get($criativo->path)),
        ]);

        $hash = collect($r['images'] ?? [])->first()['hash'] ?? null;
        if (! $hash) {
            throw new RuntimeException('O Facebook aceitou a imagem mas não devolveu o hash.');
        }

        $criativo->forceFill(['fb_image_hash' => $hash])->save();

        return $hash;
    }

    public function criarAnuncio(
        string $conjuntoId,
        string $nome,
        MarketingCreative $criativo,
        string $mensagem,
        ?string $titulo,
        ?string $link,
        ?string $descricao = null,
        ?string $cta = null,
    ): array {
        $pageId = trim((string) $this->cred->page_id);
        if ($pageId === '') {
            throw new RuntimeException('Falta o ID da página do Facebook na configuração — todo anúncio precisa de uma página.');
        }

        $hash = $this->garantirImagem($criativo);

        $linkData = array_filter([
            'image_hash' => $hash,
            'message' => $mensagem,
            'name' => $titulo,
            'description' => $descricao,
            'link' => $link ?: 'https://facebook.com/'.$pageId,
            // Clique-para-WhatsApp: o destino é declarado no botão; o telefone e o texto
            // pré-preenchido vão no próprio `link` (api.whatsapp.com/send?phone=…&text=…).
            'call_to_action' => $cta
                ? array_filter(['type' => $cta, 'value' => $cta === 'WHATSAPP_MESSAGE' ? ['app_destination' => 'WHATSAPP'] : null])
                : null,
        ], fn ($v) => $v !== null && $v !== '');

        $creative = $this->call('POST', '/'.$this->conta().'/adcreatives', [
            'name' => $nome.' — '.$criativo->name,
            'object_story_spec' => json_encode(['page_id' => $pageId, 'link_data' => $linkData]),
            // Nada de melhoria automática: o texto e a imagem que saem são os que o usuário
            // aprovou. `standard_enhancements` era o atalho para isso e o Facebook passou a
            // RECUSAR a criação inteira (code 100, subcode 3858504, "defina recursos
            // individuais"). `advantage_plus_creative` é o guarda-chuva que substituiu o
            // atalho: mandando só ele, a Graph API grava os 82 recursos como OPT_OUT.
            'degrees_of_freedom_spec' => json_encode(['creative_features_spec' => ['advantage_plus_creative' => ['enroll_status' => 'OPT_OUT']]]),
        ]);

        return $this->call('POST', '/'.$this->conta().'/ads', [
            'name' => $nome,
            'adset_id' => $conjuntoId,
            'creative' => json_encode(['creative_id' => $creative['id']]),
            'status' => self::STATUS_CRIACAO,
        ]);
    }

    /**
     * Lista todos os anúncios da conta com campaign_id — usado para cruzar o referral
     * do WhatsApp (anuncio.id = ad_id) com a campanha correspondente no painel.
     */
    public function listarAnuncios(int $limite = 500): array
    {
        $r = $this->call('GET', '/'.$this->conta().'/ads', [
            'fields' => 'id,name,campaign_id',
            'limit' => max(1, min($limite, 500)),
        ]);

        return $r['data'] ?? [];
    }

    /**
     * Quais anúncios usam cada imagem da biblioteca — a ponte entre "Criativo 3" e o
     * desempenho, que a Meta só reporta por ad_id.
     *
     * O hash mora em um de dois lugares conforme como o anúncio foi montado: solto em
     * `creative.image_hash` (imagem única) ou dentro do `object_story_spec.link_data`
     * — que é como ESTE sistema cria. Ler só o primeiro deixava justamente os anúncios
     * criados aqui sem desempenho nenhum.
     *
     * @return array<string, list<array{id: string, name: string, ativo: bool}>> image_hash => anúncios
     */
    public function anunciosPorImagem(int $limite = 500): array
    {
        $r = $this->call('GET', '/'.$this->conta().'/ads', [
            'fields' => 'id,name,effective_status,creative{image_hash,object_story_spec}',
            'limit' => max(1, min($limite, 500)),
        ]);

        $out = [];
        foreach ($r['data'] ?? [] as $ad) {
            $c = $ad['creative'] ?? [];
            $hash = $c['image_hash'] ?? ($c['object_story_spec']['link_data']['image_hash'] ?? null);
            if (! $hash || empty($ad['id'])) {
                continue;
            }
            $out[$hash][] = [
                'id' => (string) $ad['id'],
                'name' => (string) ($ad['name'] ?? ''),
                'ativo' => ($ad['effective_status'] ?? '') === 'ACTIVE',
            ];
        }

        return $out;
    }

    /**
     * Gasto e entrega de cada anúncio no período.
     *
     * Não dá para reusar {@see metricas()}: ela para em 50 linhas, e no nível de ANÚNCIO
     * uma conta com algumas campanhas passa disso fácil — o criativo que ficasse de fora
     * apareceria na tela com gasto zero, como se nunca tivesse rodado.
     *
     * @return array<string, array{gasto: float, impressoes: int, cliques: int}> ad_id => números
     */
    public function gastoPorAnuncio(string $periodo = 'last_7d', ?string $de = null, ?string $ate = null): array
    {
        $params = [
            'level' => 'ad',
            'fields' => 'ad_id,impressions,clicks,spend',
            'limit' => 500,
        ];
        if ($de && $ate) {
            $params['time_range'] = json_encode(['since' => $de, 'until' => $ate]);
        } else {
            $params['date_preset'] = $periodo;
        }

        $r = $this->call('GET', '/'.$this->conta().'/insights', $params);

        $out = [];
        foreach ($r['data'] ?? [] as $l) {
            $id = (string) ($l['ad_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[$id] = [
                'gasto' => (float) ($l['spend'] ?? 0),
                'impressoes' => (int) ($l['impressions'] ?? 0),
                'cliques' => (int) ($l['clicks'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Miniatura do criativo de cada campanha, para a tabela do painel.
     *
     * A imagem é o que identifica o anúncio para quem o criou — "Criativo 5" não diz nada
     * três semanas depois, e é sobre ela que se decide o que pausar.
     *
     * Prefere o anúncio ATIVO da campanha: campanha antiga costuma acumular anúncios
     * pausados, e mostrar a arte de um que não roda mais faria julgar o desempenho de
     * hoje pela imagem errada.
     *
     * @return array<string, string> campaign_id => URL da miniatura
     */
    public function miniaturasPorCampanha(int $limite = 500): array
    {
        $r = $this->call('GET', '/'.$this->conta().'/ads', [
            'fields' => 'campaign_id,effective_status,creative{thumbnail_url}',
            'limit' => max(1, min($limite, 500)),
        ]);

        $ativas = [];
        $pausadas = [];
        foreach ($r['data'] ?? [] as $ad) {
            $camp = (string) ($ad['campaign_id'] ?? '');
            $url = (string) ($ad['creative']['thumbnail_url'] ?? '');
            if ($camp === '' || $url === '') {
                continue;
            }
            if (($ad['effective_status'] ?? '') === 'ACTIVE') {
                $ativas[$camp] ??= $url;
            } else {
                $pausadas[$camp] ??= $url;
            }
        }

        // Ativa ganha da pausada quando a campanha tem das duas.
        return array_replace($pausadas, $ativas);
    }

    /**
     * Onde mora o orçamento de cada campanha e quanto é, somando os conjuntos.
     *
     * Sem isto a coluna do painel ficava em "—" nesta conta inteira: o orçamento é por
     * conjunto (ABO) e a campanha vem com `daily_budget` vazio, então a célula não tinha
     * o que mostrar nem virava clicável — não dava para editar verba pelo painel.
     *
     * `conjuntos` viaja junto porque é o que decide se dá para editar daqui: com dois ou
     * mais, escolher em qual mexer é decisão de quem cuida da conta, não do painel.
     *
     * @return array<string, array{diario: int, conjuntos: int}> campaign_id => centavos
     */
    public function orcamentosDeConjunto(int $limite = 500): array
    {
        $r = $this->call('GET', '/'.$this->conta().'/adsets', [
            'fields' => 'campaign_id,daily_budget',
            'limit' => max(1, min($limite, 500)),
        ]);

        $out = [];
        foreach ($r['data'] ?? [] as $conj) {
            $camp = (string) ($conj['campaign_id'] ?? '');
            if ($camp === '') {
                continue;
            }
            $out[$camp] ??= ['diario' => 0, 'conjuntos' => 0];
            $out[$camp]['diario'] += (int) ($conj['daily_budget'] ?? 0);
            $out[$camp]['conjuntos']++;
        }

        return $out;
    }

    /**
     * Métricas. `nivel` = account | campaign | adset | ad.
     *
     * Com `$de`/`$ate` (YYYY-MM-DD) usa `time_range` em vez de `date_preset` — é assim que
     * o painel consulta um intervalo escolhido no calendário. Atenção: o `until` da Graph
     * API é INCLUSIVO, ao contrário da janela do CRM, que é [de, ate).
     */
    public function metricas(string $nivel = 'campaign', string $periodo = 'last_7d', ?string $id = null, ?string $de = null, ?string $ate = null): array
    {
        $alvo = $id ?: $this->conta();
        $params = [
            'level' => $nivel,
            'fields' => 'campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,impressions,clicks,ctr,spend,cpc,cpm,actions',
            'limit' => 50,
        ];
        if ($de && $ate) {
            $params['time_range'] = json_encode(['since' => $de, 'until' => $ate]);
        } else {
            $params['date_preset'] = $periodo;
        }

        $r = $this->call('GET', '/'.$alvo.'/insights', $params);

        return $r['data'] ?? [];
    }

    /**
     * Insights quebrados por um recorte da Meta (idade, gênero, posicionamento, região…).
     *
     * O QUE ISTO NÃO É: qualificação. No nível de conta o único "resultado" que a Meta
     * conhece nesta operação é conversa iniciada por mensagem — quem qualificou está no
     * CRM e não tem como voltar para cá. Quem ler estas linhas como qualidade de público
     * repete a inversão já medida nesta conta, onde lead barato e lead bom andam em
     * direções OPOSTAS.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(string $breakdowns, string $periodo = 'last_30d', ?string $de = null, ?string $ate = null): array
    {
        $params = [
            'level' => 'account',
            'fields' => 'impressions,clicks,ctr,spend,cpm,cpc,actions',
            'breakdowns' => $breakdowns,
            'limit' => 200,
        ];
        if ($de && $ate) {
            $params['time_range'] = json_encode(['since' => $de, 'until' => $ate]);
        } else {
            $params['date_preset'] = $periodo;
        }

        $r = $this->call('GET', '/'.$this->conta().'/insights', $params);

        $chaves = explode(',', $breakdowns);
        $linhas = [];
        foreach ($r['data'] ?? [] as $l) {
            $rotulo = implode(' · ', array_filter(array_map(fn ($k) => $l[$k] ?? null, $chaves)));
            if ($rotulo === '') {
                continue;
            }

            // "Conversa iniciada" tem nomes diferentes conforme a campanha; somar os dois
            // evita a linha zerada que faria o recorte parecer não ter entregado nada.
            $conversas = 0;
            foreach ($l['actions'] ?? [] as $a) {
                if (in_array($a['action_type'] ?? '', ['onsite_conversion.messaging_conversation_started_7d', 'onsite_conversion.total_messaging_connection'], true)) {
                    $conversas += (int) ($a['value'] ?? 0);
                }
            }

            $gasto = (float) ($l['spend'] ?? 0);
            $linhas[] = [
                'valor' => $rotulo,
                'gasto' => round($gasto, 2),
                'impressoes' => (int) ($l['impressions'] ?? 0),
                'cliques' => (int) ($l['clicks'] ?? 0),
                'ctr' => round((float) ($l['ctr'] ?? 0), 2),
                'cpm' => round((float) ($l['cpm'] ?? 0), 2),
                'conversas' => $conversas,
                'custo_conversa' => $conversas > 0 && $gasto > 0 ? round($gasto / $conversas, 2) : null,
            ];
        }

        usort($linhas, fn ($a, $b) => $b['gasto'] <=> $a['gasto']);

        return $linhas;
    }

    /**
     * Atualiza uma campanha existente: status (ACTIVE|PAUSED) e/ou orçamento diário.
     * Não cria nada — é gestão do que já existe no Facebook.
     */
    public function atualizarCampanha(string $campanhaId, array $params): array
    {
        $data = [];
        if (isset($params['status'])) {
            $data['status'] = $params['status'];
        }
        if (empty($data)) {
            throw new RuntimeException('Nenhum campo para atualizar.');
        }

        return $this->call('POST', "/{$campanhaId}", $data);
    }

    /**
     * Orçamento diário — na campanha OU no conjunto, conforme onde ele mora.
     *
     * O Facebook guarda o orçamento em um dos dois níveis, nunca nos dois: CBO põe na
     * campanha, ABO põe em cada conjunto. Escrever no nível errado não é "não faz nada":
     * a Graph API recusa, e o painel mostrava "—" na coluna e engolia o erro ao salvar,
     * porque esta conta é ABO e o código só sabia falar com a campanha.
     *
     * Com mais de um conjunto a resposta é recusar, não dividir: escolher sozinho em qual
     * deles mexer é decidir verba no lugar de quem pediu.
     */
    public function atualizarOrcamentoDiario(string $campanhaId, float $reais): array
    {
        $centavos = (int) round($reais * 100);

        $camp = $this->call('GET', "/{$campanhaId}", ['fields' => 'daily_budget,lifetime_budget']);
        if (! empty($camp['daily_budget'])) {
            return $this->call('POST', "/{$campanhaId}", ['daily_budget' => $centavos]);
        }
        if (! empty($camp['lifetime_budget'])) {
            throw new RuntimeException('Esta campanha usa orçamento total (vitalício), não diário. Ajuste pelo Facebook.');
        }

        $conjuntos = $this->call('GET', "/{$campanhaId}/adsets", [
            'fields' => 'id,name,daily_budget',
            'limit' => 50,
        ])['data'] ?? [];

        if (count($conjuntos) === 0) {
            throw new RuntimeException('Campanha sem conjunto de anúncios — não há onde gravar o orçamento.');
        }
        if (count($conjuntos) > 1) {
            $nomes = implode(', ', array_map(fn ($c) => (string) ($c['name'] ?? $c['id']), $conjuntos));
            throw new RuntimeException("Esta campanha tem {$nomes} — o orçamento é de cada conjunto. Ajuste pelo Facebook para escolher qual.");
        }

        return $this->call('POST', '/'.$conjuntos[0]['id'], ['daily_budget' => $centavos]);
    }

    /**
     * Duplica a campanha inteira (conjuntos e anúncios), como o "Duplicar" do Facebook.
     *
     * Nasce PAUSADA de propósito: cópia é rascunho, e uma que já entra no ar começa a
     * gastar antes de alguém revisar público, orçamento ou criativo.
     */
    public function duplicarCampanha(string $campanhaId, string $sufixo = ' (cópia)'): array
    {
        return $this->call('POST', "/{$campanhaId}/copies", [
            'deep_copy' => 'true',
            'status_option' => 'PAUSED',
            'rename_options' => json_encode(['rename_suffix' => $sufixo]),
        ]);
    }
}
