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

    public function criarConjunto(
        string $campanhaId,
        string $nome,
        ?float $orcamentoDiarioReais,
        string $otimizacao,
        array $segmentacao,
        ?string $billingEvent = null,
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

    /** Métricas. `nivel` = account | campaign | adset | ad. */
    public function metricas(string $nivel = 'campaign', string $periodo = 'last_7d', ?string $id = null): array
    {
        $alvo = $id ?: $this->conta();
        $r = $this->call('GET', '/'.$alvo.'/insights', [
            'level' => $nivel,
            'date_preset' => $periodo,
            'fields' => 'campaign_name,adset_name,ad_name,impressions,clicks,ctr,spend,cpc,cpm,actions',
            'limit' => 50,
        ]);

        return $r['data'] ?? [];
    }
}
