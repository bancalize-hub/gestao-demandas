<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Support\Claude;
use Illuminate\Console\Command;

/**
 * Agrupa em categorias as objeções que a auditoria das calls extraiu.
 *
 * Na 1ª rodada (18/08/2026) o ranking saiu inútil: cada objeção era uma frase única, então
 * todas apareciam "1x" e nada agregava — o mesmo erro que o vocabulário FECHADO de erros
 * evitou do outro lado. Aqui a classificação é uma passada barata sobre o texto já
 * extraído (uma chamada de IA para o lote inteiro), e não uma reanálise das calls.
 *
 * O ranking serve para uma coisa concreta: objeção que aparece muito e fica sem resposta
 * é conteúdo faltando na base de conhecimento que a IA usa no WhatsApp.
 */
class MeetingsObjecoes extends Command
{
    protected $signature = 'meetings:objecoes
        {--desde=2026-08-01}
        {--reclassificar : refaz a classificação de quem já tem categoria}';

    protected $description = 'Classifica e ranqueia as objeções levantadas nas calls';

    private const CATEGORIAS = [
        'dependencia_adquirente' => 'depender de uma única adquirente/subadquirente, medo de quebra',
        'preco_entrada' => 'valor do setup, entrada, parcelamento, caixa apertado',
        'taxas_operacao' => 'custo por transação, TPV, competitividade de taxa',
        'concorrencia' => 'já cotou ou fechou com outro fornecedor, comparando propostas',
        'compatibilidade_hardware' => 'maquininhas, equipamento travado em outra adquirente',
        'integracao_tecnica' => 'API, hospedagem própria, domínio/CNAME, integrar ao sistema atual',
        'escopo_produto' => 'quer só uma parte (BaaS/API) ou função que a plataforma não entrega',
        'titularidade_conta' => 'conta em nome de quem, bloqueio judicial, conta bolsão, medo fiscal',
        'decisor_terceiro' => 'precisa consultar sócio, familiar ou diretoria',
        'confianca_fornecedor' => 'desconfiança da empresa, dos parceiros listados ou de experiência anterior',
        'processo_burocratico' => 'aprovação cadastral, homologação, prazo de implantação',
        'outro' => 'não cabe em nenhuma acima',
    ];

    public function handle(): int
    {
        $reunioes = Meeting::withoutGlobalScopes()
            ->whereNotNull('call_review')
            ->where('starts_at', '>=', $this->option('desde'))
            ->where('title', 'not like', '%Onboarding%')
            ->get();

        // Lote: [ [meeting_id, indice, texto, respondida], ... ]
        $itens = [];
        foreach ($reunioes as $m) {
            foreach (($m->call_review['objecoes'] ?? []) as $i => $o) {
                $texto = trim((string) ($o['objecao'] ?? ''));
                if ($texto === '') {
                    continue;
                }
                if (! $this->option('reclassificar') && ! empty($o['categoria'])) {
                    continue;
                }
                $itens[] = ['meeting' => $m->id, 'i' => $i, 'texto' => $texto,
                    'respondida' => (bool) ($o['respondida'] ?? true)];
            }
        }

        if ($itens) {
            $this->info(count($itens).' objeção(ões) para classificar.');
            $categorias = $this->classificar($itens);

            if ($categorias === null) {
                $this->error('A IA não devolveu a classificação — ranking sai só com o que já tinha categoria.');
            } else {
                foreach ($reunioes as $m) {
                    $review = $m->call_review;
                    $mudou = false;
                    foreach ($itens as $k => $item) {
                        if ($item['meeting'] !== $m->id) {
                            continue;
                        }
                        $cat = $categorias[$k] ?? 'outro';
                        $review['objecoes'][$item['i']]['categoria'] = $cat;
                        $mudou = true;
                    }
                    if ($mudou) {
                        $m->forceFill(['call_review' => $review])->save();
                    }
                }
            }
        }

        $this->ranking();

        return self::SUCCESS;
    }

    /**
     * @param  list<array{meeting:int,i:int,texto:string,respondida:bool}>  $itens
     * @return array<int, string>|null índice do lote => categoria
     */
    private function classificar(array $itens): ?array
    {
        $lista = '';
        foreach ($itens as $k => $item) {
            $lista .= "{$k}. {$item['texto']}\n";
        }
        $cats = '';
        foreach (self::CATEGORIAS as $chave => $desc) {
            $cats .= "  - {$chave}: {$desc}\n";
        }

        $resposta = Claude::run(<<<TXT
        Abaixo estão objeções REAIS levantadas por leads em calls de venda de uma plataforma
        de pagamentos whitelabel (o cliente vira dono da própria marca de fintech).

        Classifique CADA UMA em exatamente uma destas categorias:
        {$cats}
        Responda SOMENTE com um JSON no formato {"0":"categoria","1":"categoria",...}, uma
        entrada para cada número da lista, sem texto fora do JSON.

        OBJEÇÕES:
        {$lista}
        TXT, 300);

        if (! $resposta) {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $resposta, $m)) {
            $resposta = $m[1];
        }
        $ini = strpos($resposta, '{');
        $fim = strrpos($resposta, '}');
        if ($ini === false || $fim === false) {
            return null;
        }
        $dados = json_decode(substr($resposta, $ini, $fim - $ini + 1), true);
        if (! is_array($dados)) {
            return null;
        }

        $out = [];
        foreach ($dados as $k => $v) {
            $cat = is_string($v) ? trim($v) : 'outro';
            $out[(int) $k] = array_key_exists($cat, self::CATEGORIAS) ? $cat : 'outro';
        }

        return $out;
    }

    private function ranking(): void
    {
        $agg = [];
        $exemplos = [];

        foreach (Meeting::withoutGlobalScopes()->whereNotNull('call_review')
            ->where('starts_at', '>=', $this->option('desde'))
            ->where('title', 'not like', '%Onboarding%')->get() as $m) {
            foreach (($m->call_review['objecoes'] ?? []) as $o) {
                $cat = (string) ($o['categoria'] ?? 'sem categoria');
                $agg[$cat]['n'] = ($agg[$cat]['n'] ?? 0) + 1;
                $agg[$cat]['sem'] = ($agg[$cat]['sem'] ?? 0) + (($o['respondida'] ?? true) ? 0 : 1);
                if (count($exemplos[$cat] ?? []) < 2) {
                    $exemplos[$cat][] = mb_substr((string) ($o['objecao'] ?? ''), 0, 60);
                }
            }
        }

        uasort($agg, fn ($a, $b) => $b['n'] <=> $a['n']);

        $this->newLine();
        $this->info('== OBJEÇÕES POR CATEGORIA ==');
        $linhas = [];
        foreach ($agg as $cat => $v) {
            $linhas[] = [$cat, $v['n'], $v['sem'], implode(' · ', $exemplos[$cat] ?? [])];
        }
        $this->table(['categoria', 'vezes', 'sem resposta', 'exemplos'], $linhas);
    }
}
