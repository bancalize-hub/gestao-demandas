<?php

namespace App\Console\Commands;

use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeRequest;
use App\Services\Marketing\DesignerAgente;
use App\Support\ImageGen;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * As ferramentas do agente designer. Empresa, pedido e sessão vêm do ambiente montado pelo
 * {@see DesignerAgente} — o agente não escolhe em que pedido trabalha.
 */
class DesignerFerramenta extends Command
{
    protected $signature = 'designer
        {acao? : pedido | gerar | entregar | falhar}
        {rascunhos? : entregar: ids dos rascunhos separados por vírgula}
        {--prompt= : gerar: a peça inteira descrita para o gerador}
        {--manchete= : gerar: texto exato da manchete}
        {--texto= : gerar: demais textos exatos da imagem}
        {--registro= : gerar: jornal | classificado | comunicado | demonstrativo | dossie | quadro | outro}
        {--gatilho= : gerar: volume | construir | marca | atende | caminho | preco | outro}
        {--mensagem= : entregar: texto do anúncio sugerido}
        {--titulo= : entregar: headline sugerida}
        {--nota= : entregar: o que a peça é e o que testa}
        {--motivo= : falhar}';

    protected $description = 'Ferramentas do agente designer (ler o pedido, gerar rascunho, entregar criativo)';

    public function handle(Tenancy $tenancy): int
    {
        $empresa = (int) (getenv('GESTOR_EMPRESA') ?: 0);
        $pedidoId = (int) (getenv('GESTOR_PEDIDO') ?: 0);
        if ($empresa <= 0 || $pedidoId <= 0) {
            $this->error('Esta ferramenta só roda dentro de um turno do designer.');

            return self::FAILURE;
        }

        return $tenancy->run($empresa, function () use ($pedidoId) {
            $pedido = MarketingCreativeRequest::find($pedidoId);
            if (! $pedido) {
                $this->line("Pedido #{$pedidoId} não existe.");

                return self::FAILURE;
            }
            try {
                $saida = match ((string) $this->argument('acao')) {
                    'pedido' => $this->verPedido($pedido),
                    'gerar' => $this->gerar($pedido),
                    'entregar' => $this->entregar($pedido),
                    'falhar' => $this->falhar($pedido),
                    default => 'Ações: pedido | gerar --prompt= --manchete= [--texto=] [--registro=] [--gatilho=] | entregar <rascunho,…> --mensagem= [--titulo=] --nota= | falhar --motivo=',
                };
            } catch (\Throwable $e) {
                $this->line('ERRO: '.$e->getMessage());

                return self::FAILURE;
            }
            $this->line(is_string($saida) ? $saida : json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        });
    }

    /** @return array<string, mixed> */
    private function verPedido(MarketingCreativeRequest $p): array
    {
        $ref = fn (array $nums) => MarketingCreative::whereIn('number', $nums)->get()->map(fn ($c) => [
            'criativo' => $c->number, 'arquivo' => $c->original_name, 'status' => $c->status, 'notas' => $c->notes,
            'imagem' => $c->path ? Storage::path($c->path) : null,
        ])->all();

        return [
            'pedido' => $p->id,
            'quantidade' => $p->quantidade,
            'brief' => $p->brief,
            'por_que' => $p->motivo,
            'seguir_como_referencia' => $ref($p->referencias ?? []),
            'evitar' => $ref($p->evitar ?? []),
            'rascunhos_ja_gerados' => $this->rascunhos($p),
            'tentativas_restantes' => $this->restantes($p),
        ];
    }

    private function gerar(MarketingCreativeRequest $p): string
    {
        if ($this->restantes($p) <= 0) {
            return 'Sem tentativas restantes neste pedido: entregue o melhor rascunho ou use `designer falhar`.';
        }
        $prompt = trim((string) $this->option('prompt'));
        if (mb_strlen($prompt) < 60) {
            return 'O --prompt precisa descrever a peça inteira (layout, cores, tipografia e o texto exato entre aspas).';
        }

        $cfg = MarketingAutopilot::atual();
        $gerador = ImageGen::make($cfg); // lança se o provedor/chave não estiver configurado
        $png = $gerador->gerar($prompt."\n\nFormato vertical 4:5 (1080x1350), anúncio para o feed do Instagram. "
            .'Todo texto em português do Brasil, escrito EXATAMENTE como está entre aspas, com acentuação correta.');

        $id = Str::lower(Str::random(10));
        $path = "marketing/rascunhos/{$p->id}-{$id}.png";
        Storage::put($path, $png);
        MarketingAutopilotEvent::create(['kind' => 'rascunho', 'message' => "Designer gerou rascunho {$id} para o pedido #{$p->id}",
            'data' => ['pedido' => $p->id, 'rascunho' => $id, 'path' => $path, 'prompt' => $prompt, 'manchete' => $this->option('manchete'),
                'texto' => $this->option('texto'), 'registro' => $this->option('registro'), 'gatilho' => $this->option('gatilho'), 'modelo' => $gerador->modelo()],
            'session_id' => (int) getenv('GESTOR_SESSAO') ?: null]);

        return "Rascunho {$id} gerado. Olhe com Read: ".Storage::path($path)."\nTentativas restantes: ".$this->restantes($p);
    }

    private function entregar(MarketingCreativeRequest $p): string
    {
        $ids = array_filter(array_map('trim', explode(',', (string) $this->argument('rascunhos'))));
        $todos = collect($this->rascunhos($p))->keyBy('rascunho');
        $escolhidos = array_values(array_filter($ids, fn ($i) => isset($todos[$i])));
        if (! $escolhidos) {
            return 'Nenhum rascunho válido. Rascunhos deste pedido: '.($todos->keys()->implode(', ') ?: 'nenhum');
        }
        if (blank($this->option('mensagem'))) {
            return 'Falta --mensagem (o texto do anúncio sugerido para o gestor).';
        }

        $numeros = [];
        foreach ($escolhidos as $rid) {
            $r = $todos[$rid];
            $c = DB::transaction(function () use ($r, $p) {
                $n = MarketingCreative::lockForUpdate()->max('number') + 1;
                $destino = 'marketing/creatives/ia-'.Str::uuid().'.png';
                Storage::move($r['path_rel'], $destino);

                return MarketingCreative::create([
                    'name' => "Criativo {$n}", 'number' => $n,
                    'original_name' => sprintf('%02d-%s-%s-ia.png', $n, Str::slug($r['registro'] ?: 'ia'), Str::slug($r['gatilho'] ?: 'ia')),
                    'path' => $destino, 'mime' => 'image/png', 'size' => Storage::size($destino),
                    'notes' => trim(($r['manchete'] ?? '').' — '.$this->option('nota').' (designer IA, pedido #'.$p->id.')'),
                    'source' => 'ia', 'status' => MarketingCreative::TESTANDO,
                    'brief' => ['pedido' => $p->id, 'registro' => $r['registro'], 'gatilho' => $r['gatilho'], 'manchete' => $r['manchete'],
                        'texto_imagem' => $r['texto'], 'prompt_imagem' => $r['prompt'], 'mensagem' => $this->option('mensagem'), 'titulo' => $this->option('titulo')],
                ]);
            });
            $numeros[] = $c->number;
        }

        $p->update(['status' => MarketingCreativeRequest::PRONTO, 'creative_ids' => $numeros,
            'resultado' => 'Entregue: Criativo '.implode(', ', $numeros).'. '.$this->option('nota')."\nTexto sugerido: ".$this->option('mensagem')]);

        return 'Entregue na biblioteca: Criativo '.implode(', ', $numeros).'.';
    }

    private function falhar(MarketingCreativeRequest $p): string
    {
        $p->update(['status' => MarketingCreativeRequest::FALHOU, 'resultado' => (string) ($this->option('motivo') ?: 'sem motivo')]);

        return 'Pedido marcado como não entregue.';
    }

    /** @return list<array<string, mixed>> */
    private function rascunhos(MarketingCreativeRequest $p): array
    {
        return MarketingAutopilotEvent::where('kind', 'rascunho')->where('data->pedido', $p->id)->orderBy('id')->get()
            ->filter(fn ($e) => Storage::exists($e->data['path']))
            ->map(fn ($e) => ['rascunho' => $e->data['rascunho'], 'imagem' => Storage::path($e->data['path']), 'path_rel' => $e->data['path'],
                'manchete' => $e->data['manchete'] ?? null, 'texto' => $e->data['texto'] ?? null, 'registro' => $e->data['registro'] ?? null,
                'gatilho' => $e->data['gatilho'] ?? null, 'prompt' => $e->data['prompt'] ?? null])
            ->values()->all();
    }

    private function restantes(MarketingCreativeRequest $p): int
    {
        $feitos = MarketingAutopilotEvent::where('kind', 'rascunho')->where('data->pedido', $p->id)->count();

        return max(0, $p->quantidade * DesignerAgente::TENTATIVAS_POR_PECA - $feitos);
    }
}
