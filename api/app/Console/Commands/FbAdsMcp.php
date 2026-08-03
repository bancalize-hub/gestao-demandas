<?php

namespace App\Console\Commands;

use App\Models\MarketingCreative;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Servidor MCP (stdio) com as ferramentas do Facebook Ads.
 *
 * É ISTO que limita o agente de marketing: ele roda com `--tools ""` (nenhuma
 * ferramenta nativa: sem bash, sem ler/escrever arquivo, sem web) e só enxerga o que
 * está declarado aqui. Não há caminho para comando arbitrário — a superfície do agente
 * é exatamente a lista de `ferramentas()` abaixo.
 *
 * Protocolo: JSON-RPC 2.0 em linhas, uma mensagem por linha, no stdin/stdout.
 * NADA além do protocolo pode sair no stdout, ou o cliente desconecta.
 */
class FbAdsMcp extends Command
{
    protected $signature = 'fbads:mcp {--company= : empresa dona da credencial}';

    protected $description = 'Servidor MCP com as ferramentas do Facebook Ads (usado pelo agente de marketing)';

    public function handle(): int
    {
        // O agente roda fora de um request: sem isto o global scope de empresa não
        // acha credencial nem criativo nenhum.
        if ($empresa = $this->option('company')) {
            app(Tenancy::class)->set((int) $empresa);
        }

        $in = fopen('php://stdin', 'r');
        while (($linha = fgets($in)) !== false) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }

            $msg = json_decode($linha, true);
            if (! is_array($msg)) {
                continue;
            }

            $resposta = $this->despachar($msg);
            // Notificação (sem `id`) não tem resposta — respondê-la quebra o cliente.
            if ($resposta !== null) {
                $this->enviar($resposta);
            }
        }

        return self::SUCCESS;
    }

    private function enviar(array $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        fflush(STDOUT);
    }

    private function despachar(array $msg): ?array
    {
        $id = $msg['id'] ?? null;
        $metodo = $msg['method'] ?? '';

        if ($id === null) {
            return null; // notifications/initialized e afins
        }

        try {
            $result = match ($metodo) {
                'initialize' => [
                    // Ecoa a versão que o cliente pediu: é o que o spec manda quando suportamos.
                    'protocolVersion' => $msg['params']['protocolVersion'] ?? '2025-06-18',
                    'capabilities' => ['tools' => new \stdClass],
                    'serverInfo' => ['name' => 'fbads', 'version' => '1.0.0'],
                ],
                'ping' => new \stdClass,
                'tools/list' => ['tools' => $this->ferramentas()],
                'tools/call' => $this->chamar(
                    (string) ($msg['params']['name'] ?? ''),
                    (array) ($msg['params']['arguments'] ?? []),
                ),
                default => throw new \BadMethodCallException("Método não suportado: {$metodo}"),
            };

            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (\BadMethodCallException $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            Log::error('fbads:mcp falhou', ['metodo' => $metodo, 'e' => $e->getMessage()]);

            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => $e->getMessage()]];
        }
    }

    /** Texto simples é o que o modelo lê melhor; erro volta como isError para ele se corrigir. */
    private function texto(string $t, bool $erro = false): array
    {
        return ['content' => [['type' => 'text', 'text' => $t]], 'isError' => $erro];
    }

    private function ferramentas(): array
    {
        $obj = fn (array $props, array $req = []) => [
            'type' => 'object', 'properties' => $props, 'required' => $req, 'additionalProperties' => false,
        ];
        $str = fn (string $d) => ['type' => 'string', 'description' => $d];
        $num = fn (string $d) => ['type' => 'number', 'description' => $d];

        return [
            [
                'name' => 'conta_status',
                'description' => 'Dados da conta de anúncios conectada: nome, moeda, status e total já gasto. Use antes de criar qualquer coisa para confirmar que a conta certa está ligada.',
                'inputSchema' => $obj([]),
            ],
            [
                'name' => 'listar_criativos',
                'description' => 'Lista os criativos que o usuário subiu pela tela (Criativo 1, Criativo 2…), com as observações que ele escreveu. É a única forma de descobrir quais imagens existem.',
                'inputSchema' => $obj([]),
            ],
            [
                'name' => 'listar_campanhas',
                'description' => 'Lista as campanhas existentes na conta, com objetivo, status e orçamento.',
                'inputSchema' => $obj(['limite' => $num('quantas trazer (padrão 25)')]),
            ],
            [
                'name' => 'criar_campanha',
                'description' => 'Cria uma campanha. Ela nasce SEMPRE pausada — quem publica é o usuário. Objetivos válidos: OUTCOME_TRAFFIC, OUTCOME_LEADS, OUTCOME_SALES, OUTCOME_ENGAGEMENT, OUTCOME_AWARENESS, OUTCOME_APP_PROMOTION.',
                'inputSchema' => $obj([
                    'nome' => $str('nome da campanha'),
                    'objetivo' => $str('um dos objetivos OUTCOME_*'),
                    'orcamento_diario' => $num('orçamento diário em reais (ex.: 50). Se informado aqui, NÃO informe no conjunto.'),
                ], ['nome', 'objetivo']),
            ],
            [
                'name' => 'criar_conjunto',
                'description' => 'Cria um conjunto de anúncios (público + orçamento) dentro de uma campanha. Nasce pausado. O orçamento fica na campanha OU no conjunto, nunca nos dois.',
                'inputSchema' => $obj([
                    'campanha_id' => $str('id devolvido por criar_campanha'),
                    'nome' => $str('nome do conjunto'),
                    'orcamento_diario' => $num('orçamento diário em reais, se não estiver na campanha'),
                    'otimizacao' => $str('LINK_CLICKS, LEAD_GENERATION, OFFSITE_CONVERSIONS, IMPRESSIONS, REACH… (padrão LINK_CLICKS)'),
                    'paises' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'códigos ISO, ex.: ["BR"]'],
                    'cidades' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'keys de cidade do Facebook, se souber'],
                    'idade_min' => $num('idade mínima (13–65)'),
                    'idade_max' => $num('idade máxima (13–65)'),
                    'generos' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => '[1]=homens, [2]=mulheres, vazio=todos'],
                ], ['campanha_id', 'nome']),
            ],
            [
                'name' => 'criar_anuncio',
                'description' => 'Cria o anúncio usando um criativo já enviado pelo usuário. Nasce pausado. Referencie o criativo como "Criativo 3" ou só "3".',
                'inputSchema' => $obj([
                    'conjunto_id' => $str('id devolvido por criar_conjunto'),
                    'nome' => $str('nome do anúncio'),
                    'criativo' => $str('qual criativo usar, ex.: "Criativo 3"'),
                    'mensagem' => $str('texto principal do anúncio'),
                    'titulo' => $str('título (headline)'),
                    'descricao' => $str('descrição curta abaixo do título'),
                    'link' => $str('URL de destino'),
                    'cta' => $str('botão: LEARN_MORE, SHOP_NOW, SIGN_UP, WHATSAPP_MESSAGE, MESSAGE_PAGE…'),
                ], ['conjunto_id', 'nome', 'criativo', 'mensagem']),
            ],
            [
                'name' => 'metricas',
                'description' => 'Desempenho: impressões, cliques, CTR, gasto, CPC e CPM.',
                'inputSchema' => $obj([
                    'nivel' => $str('account, campaign, adset ou ad (padrão campaign)'),
                    'periodo' => $str('today, yesterday, last_7d, last_30d, this_month (padrão last_7d)'),
                    'id' => $str('id de uma campanha/conjunto/anúncio específico; vazio = conta toda'),
                ]),
            ],
        ];
    }

    private function chamar(string $nome, array $a): array
    {
        $fb = FacebookAds::make();
        if (! $fb->configurado() && $nome !== 'listar_criativos') {
            return $this->texto('Facebook Ads ainda não configurado. Peça ao usuário para preencher token e conta de anúncios na aba Configuração da tela de Marketing.', true);
        }

        try {
            return match ($nome) {
                'conta_status' => $this->texto($this->json($fb->resumoConta())),
                'listar_criativos' => $this->texto($this->criativos()),
                'listar_campanhas' => $this->texto($this->json($fb->listarCampanhas((int) ($a['limite'] ?? 25)))),
                'criar_campanha' => $this->texto($this->campanha($fb, $a)),
                'criar_conjunto' => $this->texto($this->conjunto($fb, $a)),
                'criar_anuncio' => $this->texto($this->anuncio($fb, $a)),
                'metricas' => $this->texto($this->json($fb->metricas(
                    $a['nivel'] ?? 'campaign', $a['periodo'] ?? 'last_7d', $a['id'] ?? null,
                ))),
                default => $this->texto("Ferramenta desconhecida: {$nome}", true),
            };
        } catch (\Throwable $e) {
            return $this->texto($e->getMessage(), true);
        }
    }

    private function json(mixed $v): string
    {
        return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function criativos(): string
    {
        $lista = MarketingCreative::orderBy('number')->get();
        if ($lista->isEmpty()) {
            return 'Nenhum criativo enviado ainda. Peça ao usuário para subir as imagens na tela de Marketing.';
        }

        return $this->json($lista->map(fn ($c) => array_filter([
            'nome' => $c->name,
            'arquivo' => $c->original_name,
            'observacoes' => $c->notes,
            'ja_enviado_ao_facebook' => (bool) $c->fb_image_hash,
        ]))->all());
    }

    private function campanha(FacebookAds $fb, array $a): string
    {
        $r = $fb->criarCampanha(
            (string) $a['nome'],
            strtoupper((string) $a['objetivo']),
            isset($a['orcamento_diario']) ? (float) $a['orcamento_diario'] : null,
        );

        return "Campanha criada PAUSADA. id={$r['id']}";
    }

    private function conjunto(FacebookAds $fb, array $a): string
    {
        $seg = ['geo_locations' => ['countries' => $a['paises'] ?? ['BR']]];
        if (! empty($a['cidades'])) {
            $seg['geo_locations']['cities'] = array_map(fn ($k) => ['key' => $k], $a['cidades']);
        }
        if (! empty($a['idade_min'])) {
            $seg['age_min'] = (int) $a['idade_min'];
        }
        if (! empty($a['idade_max'])) {
            $seg['age_max'] = (int) $a['idade_max'];
        }
        if (! empty($a['generos'])) {
            $seg['genders'] = array_map('intval', $a['generos']);
        }

        $r = $fb->criarConjunto(
            (string) $a['campanha_id'],
            (string) $a['nome'],
            isset($a['orcamento_diario']) ? (float) $a['orcamento_diario'] : null,
            strtoupper((string) ($a['otimizacao'] ?? 'LINK_CLICKS')),
            $seg,
        );

        return "Conjunto criado PAUSADO. id={$r['id']}";
    }

    private function anuncio(FacebookAds $fb, array $a): string
    {
        $criativo = MarketingCreative::porReferencia((string) $a['criativo']);
        if (! $criativo) {
            $tem = MarketingCreative::orderBy('number')->pluck('name')->implode(', ');

            return "Não achei o criativo \"{$a['criativo']}\". Disponíveis: ".($tem ?: 'nenhum');
        }

        $r = $fb->criarAnuncio(
            (string) $a['conjunto_id'],
            (string) $a['nome'],
            $criativo,
            (string) $a['mensagem'],
            $a['titulo'] ?? null,
            $a['link'] ?? null,
            $a['descricao'] ?? null,
            isset($a['cta']) ? strtoupper((string) $a['cta']) : null,
        );

        return "Anúncio criado PAUSADO com {$criativo->name}. id={$r['id']}";
    }
}
