<?php

namespace App\Console\Commands;

use App\Models\MarketingAutopilot;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeRequest;
use App\Models\MarketingMemory;
use App\Services\Marketing\Autopilot;
use App\Services\Marketing\AutopilotBrain;
use App\Services\Marketing\Avisos;
use App\Services\Marketing\Gestor;
use App\Services\Marketing\GestorAgente;
use App\Support\FacebookAds;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * As ferramentas do agente gestor de tráfego. É o ÚNICO comando que o agente pode rodar
 * (o --allowedTools só libera `php artisan gestor …`), então tudo que ele precisa enxergar ou
 * fazer na conta passa por aqui.
 *
 * Empresa, simulação e sessão vêm do ambiente que o {@see GestorAgente}
 * monta — nunca de argumento: o agente não escolhe em que empresa mexe nem desliga a
 * simulação sozinho.
 */
class GestorFerramenta extends Command
{
    protected $signature = 'gestor
        {acao? : conta | anuncios | anuncio | conversas | recortes | criativos | pedidos | diario | memoria | ordens | pausar | religar | verba | teto | teto-maximo | publicar | pedir-criativo | anotar | avisar | ordem | revogar-ordem | voltar-em}
        {alvo? : id do anúncio / orçamento, número do criativo, valor ou minutos}
        {valor? : valor em R$ (verba)}
        {--motivo= : por que (obrigatório nas ações)}
        {--forcar : mexer de novo em algo mexido nas últimas 24h}
        {--dias=30 : período (recortes)}
        {--n=6 : quantas conversas}
        {--filtro=reprovados : conversas: reprovados | qualificados | todos}
        {--todos : anuncios: inclui os parados com gasto no mês}
        {--novos : criativos: só os que nunca rodaram}
        {--mensagem= : publicar: texto principal do anúncio}
        {--titulo= : publicar: headline}
        {--idade= : publicar: faixa, ex. 25-44}
        {--genero= : publicar: 1 homem, 2 mulher, vazio = todos}
        {--plataformas= : publicar: instagram,facebook}
        {--posicoes= : publicar: stream,story,reels (Instagram)}
        {--verba= : publicar: R$/dia}
        {--brief= : pedir-criativo: o briefing}
        {--referencias= : pedir-criativo: números de criativo de base, ex. 96,97}
        {--evitar= : pedir-criativo: números de criativo cujo caminho não repetir}
        {--quantidade=1 : pedir-criativo}
        {--categoria=aprendizado : anotar}
        {--titulo-memoria= : anotar}
        {--conteudo= : anotar}
        {--confianca=hipotese : anotar: medido | hipotese}
        {--texto= : avisar / ordem}';

    protected $description = 'Ferramentas do agente gestor de tráfego (consultar e agir na conta de anúncios)';

    private Gestor $g;

    private FacebookAds $fb;

    private MarketingAutopilot $cfg;

    public function handle(Tenancy $tenancy): int
    {
        $empresa = (int) (getenv('GESTOR_EMPRESA') ?: 0);
        if ($empresa <= 0) {
            $this->error('GESTOR_EMPRESA não definido — esta ferramenta só roda dentro de um turno do gestor.');

            return self::FAILURE;
        }

        return $tenancy->run($empresa, function () {
            $this->cfg = MarketingAutopilot::atual();
            $simular = getenv('GESTOR_SIMULAR') === '1';
            $this->fb = FacebookAds::make();
            $this->g = (new Gestor($this->cfg, $this->fb, new Autopilot($this->cfg, $this->fb, new AutopilotBrain, $simular), new Avisos($this->cfg, $simular), $simular))
                ->naSessao((int) getenv('GESTOR_SESSAO') ?: null);

            $acao = (string) $this->argument('acao');
            if (in_array($acao, ['pausar', 'religar', 'verba', 'teto', 'publicar', 'pedir-criativo'], true) && blank($this->option('motivo'))) {
                $this->line('Falta --motivo="…": toda ação precisa dizer por que, com os números que a justificam.');

                return self::FAILURE;
            }

            try {
                $saida = match ($acao) {
                    'conta' => $this->conta(),
                    'anuncios' => $this->anuncios(),
                    'anuncio' => $this->anuncio((string) $this->argument('alvo')),
                    'conversas' => $this->conversas((string) $this->argument('alvo')),
                    'recortes' => $this->recortes((string) ($this->argument('alvo') ?: 'age')),
                    'criativos' => $this->criativos(),
                    'pedidos' => $this->pedidos(),
                    'diario' => implode("\n", $this->g->historico()) ?: '(nada nas últimas 72h)',
                    'memoria' => MarketingMemory::contexto(80) ?: '(memória vazia)',
                    'pausar' => $this->g->pausar((string) $this->argument('alvo'), $this->motivo()),
                    'religar' => $this->g->religar((string) $this->argument('alvo'), $this->motivo(), (bool) $this->option('forcar')),
                    'verba' => $this->g->verba((string) $this->argument('alvo'), (float) str_replace(',', '.', (string) $this->argument('valor')), $this->motivo(), (bool) $this->option('forcar')),
                    'teto' => $this->g->teto((float) str_replace(',', '.', (string) $this->argument('alvo')), $this->motivo()),
                    'publicar' => $this->g->publicar((int) $this->argument('alvo'), (string) $this->option('mensagem'), $this->option('titulo'),
                        $this->publico(), $this->option('verba') ? (float) $this->option('verba') : null, $this->motivo()),
                    'pedir-criativo' => $this->g->pedirCriativo((string) $this->option('brief'), $this->numeros('referencias'), $this->numeros('evitar'),
                        (int) $this->option('quantidade'), $this->motivo()),
                    'anotar' => $this->g->anotar((string) $this->option('categoria'), (string) $this->option('titulo-memoria'), (string) $this->option('conteudo'), (string) $this->option('confianca')),
                    'avisar' => $this->g->avisar((string) $this->option('texto')),
                    'ordens' => $this->g->ordens(),
                    'ordem' => $this->g->ordem((string) $this->option('texto')),
                    'revogar-ordem' => $this->g->revogarOrdem((int) $this->argument('alvo')),
                    'teto-maximo' => $this->g->tetoMaximo((float) str_replace(',', '.', (string) $this->argument('alvo')), (string) $this->option('motivo'), getenv('GESTOR_DONO') === '1'),
                    'voltar-em' => $this->g->voltarEm((int) $this->argument('alvo'), (string) ($this->option('motivo') ?: 'sem motivo')),
                    default => $this->ajuda(),
                };
            } catch (\Throwable $e) {
                $this->line('ERRO: '.$e->getMessage());

                return self::FAILURE;
            }

            $this->line(is_string($saida) ? $saida : json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        });
    }

    private function motivo(): string
    {
        return trim((string) $this->option('motivo'));
    }

    /** @return array<string, mixed> */
    private function conta(): array
    {
        $f = $this->g->fotografia();

        return [
            'agora' => now()->format('d/m/Y H:i (l)'),
            'saldo' => $f['saldo'],
            'status_conta' => $f['status_conta'],
            'comprometido_por_dia' => $f['comprometido'],
            'teto' => $f['teto'],
            'numeros' => $f['conta'],
            'orcamentos' => array_map(fn ($o) => array_diff_key($o, ['anuncios' => 1]) + ['anuncios' => count($o['anuncios'])], $f['orcamentos']),
            'mexidos_nas_ultimas_24h' => $f['travados_24h'],
            'anuncios_entregando' => collect($f['anuncios'])->where('status', 'ACTIVE')->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function anuncios(): array
    {
        $f = $this->g->fotografia();

        return collect($f['anuncios'])
            ->when(! $this->option('todos'), fn ($c) => $c->filter(fn ($a) => $a['status'] !== 'PAUSED' && $a['status'] !== 'CAMPAIGN_PAUSED' && $a['status'] !== 'ADSET_PAUSED' || $a['gasto']['7d'] > 0))
            ->map(fn ($a) => array_diff_key($a, ['dia_a_dia' => 1, 'motivos_reprovacao_7d' => 1]))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function anuncio(string $id): array
    {
        $f = $this->g->fotografia();
        $a = collect($f['anuncios'])->firstWhere('id', $id);
        if (! $a) {
            return ['erro' => "anúncio {$id} não encontrado (entre os ativos ou com gasto no mês)"];
        }
        $d = $this->fb->detalheAnuncio($id);
        $cr = $d['creative'] ?? [];
        $link = $cr['object_story_spec']['link_data'] ?? [];
        $hash = $cr['image_hash'] ?? ($link['image_hash'] ?? null);
        $criativo = $hash ? MarketingCreative::where('fb_image_hash', $hash)->first() : null;

        return $a + [
            'texto_do_anuncio' => $cr['body'] ?? ($link['message'] ?? null),
            'titulo' => $cr['title'] ?? ($link['name'] ?? null),
            'criativo_da_biblioteca' => $criativo ? "Criativo {$criativo->number}" : null,
            // O agente olha a imagem com a ferramenta Read neste caminho.
            'imagem' => $criativo && $criativo->path ? Storage::path($criativo->path) : null,
            'imagem_url_meta' => $criativo ? null : ($cr['image_url'] ?? $cr['thumbnail_url'] ?? null),
        ];
    }

    /** O que os leads do anúncio realmente escreveram — é aqui que se vê o público que o criativo atrai. */
    private function conversas(string $adId): string
    {
        $filtro = (string) $this->option('filtro');
        $convs = DB::table('conversations')
            ->where('company_id', $this->cfg->company_id)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(custom_fields,'$.anuncio.id')) = ?", [$adId])
            ->when($filtro === 'reprovados', fn ($q) => $q->where('qualified', 0))
            ->when($filtro === 'qualificados', fn ($q) => $q->where('qualified', 1))
            ->orderByDesc('created_at')
            ->limit(max(1, min(15, (int) $this->option('n'))))
            ->get(['id', 'name', 'qualified', 'qualified_reason', 'stage', 'created_at']);
        if ($convs->isEmpty()) {
            return "Nenhuma conversa ({$filtro}) veio do anúncio {$adId}.";
        }

        $out = [];
        foreach ($convs as $c) {
            $q = $c->qualified === null ? 'sem triagem' : ($c->qualified ? 'QUALIFICADO' : 'REPROVADO');
            $out[] = "### Conversa #{$c->id} · {$c->created_at} · {$q}".($c->qualified_reason ? " ({$c->qualified_reason})" : '')." · etapa {$c->stage}";
            $msgs = DB::table('messages')->where('conversation_id', $c->id)->orderBy('ts')->limit(16)
                ->get(['is_out', 'text', 'transcript', 'type']);
            foreach ($msgs as $m) {
                $txt = trim((string) ($m->text ?: $m->transcript ?: "[{$m->type}]"));
                $out[] = ($m->is_out ? 'NÓS: ' : 'LEAD: ').Str::limit(preg_replace('/\s+/', ' ', $txt), 280);
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /** @return list<array<string, mixed>> */
    private function recortes(string $dim): array
    {
        $mapa = ['idade' => 'age', 'genero' => 'gender', 'posicionamento' => 'publisher_platform,platform_position',
            'regiao' => 'region', 'hora' => 'hourly_stats_aggregated_by_advertiser_time_zone', 'dispositivo' => 'impression_device'];
        $dim = $mapa[$dim] ?? $dim;
        $dias = max(1, min(90, (int) $this->option('dias')));

        return $this->fb->breakdown($dim, 'last_30d', now()->subDays($dias)->toDateString(), now()->toDateString());
    }

    /** @return list<array<string, mixed>> */
    private function criativos(): array
    {
        $desempenho = collect($this->g->criativos())->keyBy('criativo');

        return MarketingCreative::orderByDesc('number')->get()
            ->when($this->option('novos'), fn ($c) => $c->filter(fn ($cr) => ! isset($desempenho[$cr->number]) || $desempenho[$cr->number]['gasto'] <= 0))
            ->map(fn (MarketingCreative $c) => [
                'criativo' => $c->number,
                'arquivo' => $c->original_name,
                'status' => $c->status,
                'origem' => $c->source,
                'notas' => Str::limit((string) $c->notes, 160),
                'imagem' => $c->path ? Storage::path($c->path) : null,
            ] + array_diff_key($desempenho[$c->number] ?? [], ['criativo' => 1, 'arquivo' => 1, 'status' => 1]))
            ->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function pedidos(): array
    {
        return MarketingCreativeRequest::latest('id')->limit(15)->get()->map(fn ($r) => [
            'pedido' => $r->id, 'status' => $r->status, 'quando' => $r->created_at->format('d/m H:i'),
            'brief' => Str::limit($r->brief, 200), 'criativos_entregues' => $r->creative_ids, 'resultado' => $r->resultado,
        ])->all();
    }

    /** @return array<string, mixed>|null */
    private function publico(): ?array
    {
        if (! $this->option('idade') && ! $this->option('genero') && ! $this->option('plataformas') && ! $this->option('posicoes')) {
            return null;
        }
        $p = [];
        if (preg_match('/(\d+)\D+(\d+)/', (string) $this->option('idade'), $m)) {
            [$p['age_min'], $p['age_max']] = [(int) $m[1], (int) $m[2]];
        }
        if ($this->option('genero') !== null) {
            $p['genders'] = array_filter(array_map('intval', explode(',', (string) $this->option('genero'))));
        }
        if ($this->option('plataformas')) {
            $p['publisher_platforms'] = array_map('trim', explode(',', (string) $this->option('plataformas')));
        }
        if ($this->option('posicoes')) {
            $p['instagram_positions'] = array_map('trim', explode(',', (string) $this->option('posicoes')));
        }

        return $p;
    }

    /** @return list<int> */
    private function numeros(string $opcao): array
    {
        return array_values(array_filter(array_map('intval', preg_split('/\D+/', (string) $this->option($opcao)) ?: [])));
    }

    private function ajuda(): string
    {
        return 'Ações: conta | anuncios [--todos] | anuncio <ad_id> | conversas <ad_id> [--filtro=reprovados|qualificados|todos] [--n=6] | '
            .'recortes <idade|genero|posicionamento|regiao|hora|dispositivo> [--dias=30] | criativos [--novos] | pedidos | diario | memoria | '
            .'pausar <ad_id> --motivo= | religar <ad_id> --motivo= [--forcar] | verba <orcamento_id> <R$> --motivo= [--forcar] | teto <R$> --motivo= | '
            .'publicar <nº criativo> --mensagem= [--titulo=] [--idade=25-44 --genero=1 --plataformas=instagram --posicoes=reels] [--verba=] --motivo= | '
            .'pedir-criativo --brief= [--referencias=96,97] [--evitar=8] [--quantidade=1] --motivo= | anotar --titulo-memoria= --conteudo= [--categoria=] [--confianca=] | '
            .'avisar --texto= | ordens | ordem --texto= (instrução do dono que vale sempre) | revogar-ordem <n> | '
            .'teto-maximo <R$> --motivo= (só quando o dono pede) | voltar-em <minutos> --motivo=';
    }
}
