<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\MarketingCreative;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * CLI do Facebook Ads — é por aqui que o agente de marketing age.
 *
 * O agente é o Claude Code da assinatura (mesmo motor do /agente), com shell. Em vez
 * de ensiná-lo a montar chamadas HTTP na mão, ele chama estes comandos: os argumentos
 * são validados, a saída é JSON e, principalmente, **tudo nasce PAUSADO** — a regra
 * mora no FacebookAds e não há opção para burlá-la.
 *
 * Fala direto com a Graph API por HTTP (sem SDK do Facebook).
 */
class Fbads extends Command
{
    protected $signature = 'fbads
        {acao : conta|criativos|campanhas|criar-campanha|criar-conjunto|criar-anuncio|metricas}
        {--empresa= : id da empresa dona da conta (padrão: a principal)}
        {--nome= : nome da campanha/conjunto/anúncio}
        {--objetivo= : OUTCOME_TRAFFIC|OUTCOME_LEADS|OUTCOME_SALES|OUTCOME_ENGAGEMENT|OUTCOME_AWARENESS|OUTCOME_APP_PROMOTION}
        {--orcamento= : orçamento diário em reais (ex.: 50)}
        {--campanha= : id da campanha (para criar-conjunto)}
        {--conjunto= : id do conjunto (para criar-anuncio)}
        {--otimizacao=LINK_CLICKS : LINK_CLICKS|LEAD_GENERATION|OFFSITE_CONVERSIONS|IMPRESSIONS|REACH}
        {--paises=BR : códigos ISO separados por vírgula}
        {--idade-min= : idade mínima (13-65)}
        {--idade-max= : idade máxima (13-65)}
        {--generos= : 1=homens, 2=mulheres, vazio=todos}
        {--criativo= : qual criativo usar, ex.: "Criativo 3" ou 3}
        {--mensagem= : texto principal do anúncio}
        {--titulo= : título (headline)}
        {--descricao= : descrição curta}
        {--link= : URL de destino}
        {--cta= : LEARN_MORE|SHOP_NOW|SIGN_UP|WHATSAPP_MESSAGE|MESSAGE_PAGE}
        {--limite=25 : quantos itens listar}
        {--nivel=campaign : account|campaign|adset|ad (métricas)}
        {--periodo=last_7d : today|yesterday|last_7d|last_30d|this_month}
        {--id= : id específico para métricas}';

    protected $description = 'Facebook Ads: conta, criativos, campanhas, métricas e criação (sempre PAUSADO)';

    public function handle(): int
    {
        // Fora de um request não há empresa no contexto, e sem ela o global scope
        // não acha credencial nem criativo nenhum.
        $empresa = (int) ($this->option('empresa') ?: Company::orderBy('id')->value('id'));
        app(Tenancy::class)->set($empresa);

        try {
            $resultado = match ($this->argument('acao')) {
                'conta' => FacebookAds::make()->resumoConta(),
                'criativos' => $this->criativos(),
                'campanhas' => FacebookAds::make()->listarCampanhas((int) $this->option('limite')),
                'criar-campanha' => $this->criarCampanha(),
                'criar-conjunto' => $this->criarConjunto(),
                'criar-anuncio' => $this->criarAnuncio(),
                'metricas' => FacebookAds::make()->metricas(
                    (string) $this->option('nivel'),
                    (string) $this->option('periodo'),
                    $this->option('id') ?: null,
                ),
                default => throw new \InvalidArgumentException('Ação desconhecida. Use --help para ver as válidas.'),
            };
        } catch (\Throwable $e) {
            // Erro em JSON também: o agente lê a mensagem do Facebook e se corrige sozinho.
            $this->line($this->json(['ok' => false, 'erro' => $e->getMessage()]));

            return self::FAILURE;
        }

        $this->line($this->json(['ok' => true, 'resultado' => $resultado]));

        return self::SUCCESS;
    }

    private function json(mixed $v): string
    {
        return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function exigir(string $opcao): string
    {
        $v = trim((string) $this->option($opcao));
        if ($v === '') {
            throw new \InvalidArgumentException("Falta --{$opcao}.");
        }

        return $v;
    }

    private function criativos(): array
    {
        return MarketingCreative::orderBy('number')->get()
            ->map(fn ($c) => array_filter([
                'nome' => $c->name,
                'arquivo' => $c->original_name,
                'observacoes' => $c->notes,
                'ja_enviado_ao_facebook' => (bool) $c->fb_image_hash,
            ]))->all();
    }

    private function criarCampanha(): array
    {
        $r = FacebookAds::make()->criarCampanha(
            $this->exigir('nome'),
            strtoupper($this->exigir('objetivo')),
            $this->option('orcamento') !== null ? (float) $this->option('orcamento') : null,
        );

        return ['id' => $r['id'], 'status' => 'PAUSED', 'aviso' => 'Campanha criada pausada — revise e publique no Gerenciador de Anúncios.'];
    }

    private function criarConjunto(): array
    {
        $paises = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('paises')))));
        $seg = ['geo_locations' => ['countries' => $paises ?: ['BR']]];

        if ($this->option('idade-min')) {
            $seg['age_min'] = (int) $this->option('idade-min');
        }
        if ($this->option('idade-max')) {
            $seg['age_max'] = (int) $this->option('idade-max');
        }
        if ($this->option('generos')) {
            $seg['genders'] = array_map('intval', array_filter(explode(',', (string) $this->option('generos'))));
        }

        $r = FacebookAds::make()->criarConjunto(
            $this->exigir('campanha'),
            $this->exigir('nome'),
            $this->option('orcamento') !== null ? (float) $this->option('orcamento') : null,
            strtoupper((string) $this->option('otimizacao')),
            $seg,
        );

        return ['id' => $r['id'], 'status' => 'PAUSED', 'aviso' => 'Conjunto criado pausado.'];
    }

    private function criarAnuncio(): array
    {
        $ref = $this->exigir('criativo');
        $criativo = MarketingCreative::porReferencia($ref);
        if (! $criativo) {
            $tem = MarketingCreative::orderBy('number')->pluck('name')->implode(', ');
            throw new \InvalidArgumentException("Não achei o criativo \"{$ref}\". Disponíveis: ".($tem ?: 'nenhum'));
        }

        $r = FacebookAds::make()->criarAnuncio(
            $this->exigir('conjunto'),
            $this->exigir('nome'),
            $criativo,
            $this->exigir('mensagem'),
            $this->option('titulo') ?: null,
            $this->option('link') ?: null,
            $this->option('descricao') ?: null,
            $this->option('cta') ? strtoupper((string) $this->option('cta')) : null,
        );

        return [
            'id' => $r['id'],
            'criativo' => $criativo->name,
            'status' => 'PAUSED',
            'aviso' => 'Anúncio criado pausado — revise e publique no Gerenciador de Anúncios.',
        ];
    }
}
