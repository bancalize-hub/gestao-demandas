<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Support\Claude;
use Illuminate\Console\Command;

/**
 * Audita a CONDUÇÃO das calls a partir da transcrição e cruza com o que aconteceu depois.
 *
 * Duas decisões de método, ambas para o resultado não ser profecia auto-realizável:
 *
 * 1. **A revisão é CEGA ao desfecho.** O prompt não diz se o lead avançou ou sumiu. Sabendo
 *    o final, o modelo racionaliza — acha defeito em toda call perdida e elogia toda call
 *    ganha. Cego, ele descreve a call; a correlação com o desfecho é feita depois, em código.
 * 2. **Vocabulário FECHADO de erros.** Texto livre não agrega: "não explorou a dor" e "faltou
 *    descoberta" viram duas linhas de 1 ocorrência. Com lista fixa dá para contar.
 */
class MeetingsAnalisarCalls extends Command
{
    protected $signature = 'meetings:analisar-calls
        {--desde=2026-08-01 : reuniões a partir desta data}
        {--id= : analisa só esta reunião}
        {--limite=0 : para depois de N análises}
        {--min-chars=4000 : ignora transcrição curta demais para sustentar uma leitura}
        {--forcar : reanalisa quem já tem revisão}
        {--relatorio : não analisa nada, só agrega o que já existe}';

    protected $description = 'Analisa a condução das calls transcritas e correlaciona com a conversão';

    /** Vocabulário fechado — mudar aqui muda o que dá para contar no relatório. */
    private const ERROS = [
        'sem_descoberta' => 'apresentou antes de entender o negócio do lead',
        'falou_demais' => 'monopolizou a call (lead quase não falou)',
        'preco_sem_valor' => 'preço apresentado sem ancorar valor antes',
        'preco_evitado' => 'fugiu do preço quando perguntado',
        'objecao_sem_resposta' => 'objeção do lead ficou sem resposta',
        'sem_proximo_passo' => 'call terminou sem próximo passo com data',
        'proximo_passo_vago' => 'próximo passo existe mas sem data/compromisso',
        'promessa_indevida' => 'prometeu algo que o produto não entrega',
        'demo_longa' => 'demonstração longa e genérica, sem ligar ao caso do lead',
        'nao_checou_decisor' => 'não confirmou quem decide',
        'nao_checou_orcamento' => 'não confirmou capacidade de investimento',
        'jargao_demais' => 'linguagem técnica que o lead não acompanhou',
        'lead_conduziu' => 'o lead conduziu a call do começo ao fim',
        'nao_tratou_prazo' => 'não tratou prazo/urgência de implantação',
    ];

    public function handle(): int
    {
        if (! $this->option('relatorio')) {
            $this->analisar();
        }

        $this->relatorio();

        return self::SUCCESS;
    }

    private function analisar(): void
    {
        // Sala que abriu e fechou gera transcrição de 2 KB — analisar isso só produz
        // "erro" inventado a partir de nada.
        $q = Meeting::withoutGlobalScopes()
            ->whereNotNull('transcript')
            ->whereRaw('CHAR_LENGTH(transcript) >= ?', [(int) $this->option('min-chars')])
            ->where('starts_at', '>=', $this->option('desde'))
            ->orderByDesc('starts_at');

        if ($id = $this->option('id')) {
            $q->where('id', (int) $id);
        }
        if (! $this->option('forcar')) {
            $q->whereNull('call_review');
        }

        $reunioes = $q->get();
        $this->info($reunioes->count().' call(s) para analisar.');
        $limite = (int) $this->option('limite');
        $feitas = 0;

        foreach ($reunioes as $m) {
            if ($limite > 0 && $feitas >= $limite) {
                break;
            }

            $json = Claude::run($this->prompt($m), 600);
            $dados = $this->extrairJson((string) $json);

            if (! $dados) {
                $this->error(sprintf('  #%-4d %-32s a IA não devolveu JSON válido', $m->id, $this->corta($m->title)));

                continue;
            }

            $m->forceFill(['call_review' => $dados, 'reviewed_at' => now()])->save();
            $feitas++;

            $erros = collect($dados['erros'] ?? [])->pluck('tipo')->implode(', ');
            $this->line(sprintf('  <fg=green>#%-4d</> %-32s nota %-4s %s',
                $m->id, $this->corta($m->title), $dados['nota_conducao'] ?? '?', mb_substr($erros, 0, 70)));
        }

        $this->newLine();
        $this->info("Analisadas: {$feitas}");
    }

    /** O prompt NÃO conta o desfecho — ver o comentário da classe. */
    private function prompt(Meeting $m): string
    {
        $tipos = '';
        foreach (self::ERROS as $chave => $desc) {
            $tipos .= "  - {$chave}: {$desc}\n";
        }

        $duracao = $m->starts_at && $m->ends_at ? $m->starts_at->diffInMinutes($m->ends_at) : null;
        $transcricao = mb_substr((string) $m->transcript, 0, 120000);

        return <<<TXT
        Você audita calls de venda B2B. O vendedor é da Bancalize, que vende uma PLATAFORMA DE
        PAGAMENTOS whitelabel (o cliente vira dono da própria marca de fintech: PIX, cartão,
        boleto, maquininha, carteira, split) e também BaaS. Ticket de entrada ~R$ 20 mil de
        setup mais taxa sobre o volume processado. O comprador certo já tem base de lojistas.

        Abaixo, a transcrição REAL de uma call{$this->trechoDuracao($duracao)}. As falas vêm
        rotuladas com o nome de quem falou; a equipe da Bancalize aparece como "Bancalize
        Oficial", "Maysa Lima" ou "Guilherme Rodrigues" — todos os outros nomes são o LEAD.

        Analise SÓ o que está na transcrição. Não invente e não suponha o que aconteceu depois
        da call: você não sabe o desfecho e não deve tentar adivinhar.

        TIPOS DE ERRO permitidos (use exatamente estas chaves, no máximo 4 por call, só as que
        você conseguir sustentar com uma citação literal):
        {$tipos}

        Responda SOMENTE com este JSON, sem texto fora dele:
        {
          "resumo_1_linha": "o que essa call era, em uma linha",
          "fala_vendedor_pct": <0-100, quanto do tempo de fala foi da equipe Bancalize>,
          "perguntas_descoberta": <quantas perguntas a equipe fez sobre o negócio do lead ANTES de apresentar>,
          "entendeu_negocio_do_lead": <true|false>,
          "preco_falado": "nao" | "inicio" | "meio" | "fim",
          "preco_com_valor_antes": <true|false>,
          "objecoes": [{"objecao": "<curto>", "respondida": <true|false>}],
          "proximo_passo": "data_marcada" | "vago" | "nenhum",
          "quem_puxou_proximo_passo": "vendedor" | "lead" | "ninguem",
          "sinais_de_compra": ["<citação curta do lead>"],
          "erros": [{"tipo": "<chave da lista>", "evidencia": "<citação literal curta>", "impacto": "alto|medio|baixo"}],
          "momento_de_perda": "<citação do ponto em que a call esfriou, ou string vazia>",
          "nota_conducao": <0-10>
        }

        TRANSCRIÇÃO:
        {$transcricao}
        TXT;
    }

    private function trechoDuracao(?int $min): string
    {
        return $min ? " de {$min} minutos" : '';
    }

    /** @return array<string, mixed>|null */
    private function extrairJson(string $texto): ?array
    {
        $texto = trim($texto);
        // O CLI às vezes embrulha em ```json … ```
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $texto, $m)) {
            $texto = trim($m[1]);
        }
        $ini = strpos($texto, '{');
        $fim = strrpos($texto, '}');
        if ($ini === false || $fim === false) {
            return null;
        }
        $dados = json_decode(substr($texto, $ini, $fim - $ini + 1), true);

        return is_array($dados) ? $dados : null;
    }

    /** Cruza a revisão (cega) com o que aconteceu com o lead depois. */
    private function relatorio(): void
    {
        $reunioes = Meeting::withoutGlobalScopes()
            ->whereNotNull('call_review')
            ->whereRaw('CHAR_LENGTH(transcript) >= ?', [(int) $this->option('min-chars')])
            ->where('starts_at', '>=', $this->option('desde'))
            ->with('conversation')
            ->get();

        if ($reunioes->isEmpty()) {
            $this->warn('Nenhuma call analisada ainda.');

            return;
        }

        $avancou = ['disse-que-vai-fechar', 'negociacao', 'fechado'];
        $grupos = $reunioes->groupBy(function (Meeting $m) use ($avancou) {
            $stage = $m->conversation?->stage;
            if (! $stage) {
                return 'sem lead vinculado';
            }

            return in_array($stage, $avancou, true) ? 'avançou' : 'parou na reunião';
        });

        $this->newLine();
        $this->info('== FREQUÊNCIA DE ERRO POR DESFECHO ==');
        $linhas = [];
        foreach (self::ERROS as $chave => $desc) {
            $linha = ['erro' => $chave];
            foreach (['avançou', 'parou na reunião'] as $g) {
                $total = max(1, ($grupos[$g] ?? collect())->count());
                $com = ($grupos[$g] ?? collect())->filter(
                    fn (Meeting $m) => collect($m->call_review['erros'] ?? [])->pluck('tipo')->contains($chave),
                )->count();
                $linha[$g] = round(100 * $com / $total).'% ('.$com.')';
                $linha['_'.$g] = $com / $total;
            }
            $linha['diferença'] = round(100 * ($linha['_parou na reunião'] - $linha['_avançou'])).' pts';
            unset($linha['_avançou'], $linha['_parou na reunião']);
            $linhas[] = $linha;
        }
        usort($linhas, fn ($a, $b) => (int) $b['diferença'] <=> (int) $a['diferença']);
        $this->table(['erro', 'avançou', 'parou na reunião', 'diferença'], $linhas);

        $this->info('== MÉDIAS ==');
        foreach ($grupos as $nome => $g) {
            $n = $g->count();
            $media = fn (string $campo) => round($g->avg(fn (Meeting $m) => (float) ($m->call_review[$campo] ?? 0)), 1);
            $pct = fn (callable $f) => round(100 * $g->filter($f)->count() / max(1, $n)).'%';
            $this->line(sprintf(
                "%-20s n=%-3d fala do vendedor %s%% · %s perguntas de descoberta · nota %s · %s com próximo passo marcado · %s com objeção sem resposta",
                $nome, $n, $media('fala_vendedor_pct'), $media('perguntas_descoberta'), $media('nota_conducao'),
                $pct(fn (Meeting $m) => ($m->call_review['proximo_passo'] ?? '') === 'data_marcada'),
                $pct(fn (Meeting $m) => collect($m->call_review['objecoes'] ?? [])->contains(fn ($o) => ($o['respondida'] ?? true) === false)),
            ));
        }

        $this->newLine();
        $this->info('== OBJEÇÕES MAIS FREQUENTES (e quantas ficaram sem resposta) ==');
        $obj = [];
        foreach ($reunioes as $m) {
            foreach ($m->call_review['objecoes'] ?? [] as $o) {
                $k = mb_strtolower(mb_substr((string) ($o['objecao'] ?? ''), 0, 40));
                if ($k === '') {
                    continue;
                }
                $obj[$k]['n'] = ($obj[$k]['n'] ?? 0) + 1;
                $obj[$k]['sem'] = ($obj[$k]['sem'] ?? 0) + (($o['respondida'] ?? true) ? 0 : 1);
            }
        }
        uasort($obj, fn ($a, $b) => $b['n'] <=> $a['n']);
        foreach (array_slice($obj, 0, 12, true) as $k => $v) {
            $this->line(sprintf('  %-42s %2dx  (%d sem resposta)', mb_substr($k, 0, 42), $v['n'], $v['sem']));
        }
    }

    private function corta(?string $t): string
    {
        $t = trim((string) $t);

        return mb_strlen($t) > 32 ? mb_substr($t, 0, 31).'…' : $t;
    }
}
