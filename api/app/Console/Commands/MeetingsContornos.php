<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\MemoryChunk;
use App\Support\Claude;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Fecha o ciclo das calls: o que o melhor vendedor respondeu numa objeção vira conteúdo que
 * a IA do WhatsApp usa na próxima vez que a mesma objeção aparecer.
 *
 * Até aqui o aprendizado morria no relatório: `meetings:analisar-calls` extraía a objeção,
 * `meetings:objecoes` a agrupava em categoria — e ninguém lia. A IA do WhatsApp nunca tocou
 * nesses dados.
 *
 * Duas decisões de método:
 *
 * 1. **A resposta é CITAÇÃO, não invenção.** A passada 1 volta à transcrição e copia
 *    literalmente como a equipe respondeu. Pedir ao modelo para "escrever um contorno" a
 *    partir só do rótulo da objeção produz texto de manual de vendas — bonito, genérico e
 *    que promete o que a plataforma não entrega.
 * 2. **Categoria sem resposta boa não vira conteúdo — vira buraco no relatório.** Se ninguém
 *    nunca soube responder "quem é o titular da conta", inventar um contorno é pior do que
 *    admitir que falta conteúdo: a IA passaria a responder com segurança uma coisa que a
 *    empresa nem decidiu.
 *
 * O ranking de quem entra no conteúdo usa o desfecho do lead (avançou no funil) apenas como
 * ORDENAÇÃO das citações — a leitura da call segue cega, feita antes, pelos outros comandos.
 */
class MeetingsContornos extends Command
{
    protected $signature = 'meetings:contornos
        {--desde=2026-08-01}
        {--limite=0 : para depois de N calls na leitura das respostas}
        {--min-casos=2 : categoria precisa de pelo menos N objeções para virar conteúdo}
        {--reler : relê as respostas de quem já foi lido}
        {--so-relatorio : não chama IA nem grava nada; só mostra o estado}';

    protected $description = 'Transforma as objeções das calls em conteúdo de contorno para a IA do WhatsApp';

    /** Etapas que contam como "o lead avançou depois da call" (mesma lista de analisar-calls). */
    private const AVANCOU = ['disse-que-vai-fechar', 'negociacao', 'fechado'];

    public function handle(): int
    {
        $reunioes = $this->reunioes();

        if ($reunioes->isEmpty()) {
            $this->warn('Nenhuma call analisada no período — rode meetings:analisar-calls antes.');

            return self::SUCCESS;
        }

        if (! $this->option('so-relatorio')) {
            $this->lerRespostas($reunioes);
            $reunioes = $this->reunioes();
        }

        $this->construirConteudo($reunioes);

        return self::SUCCESS;
    }

    /** @return Collection<int, Meeting> */
    private function reunioes(): Collection
    {
        return Meeting::withoutGlobalScopes()
            ->whereNotNull('call_review')
            ->whereNotNull('transcript')
            ->where('starts_at', '>=', $this->option('desde'))
            ->where('title', 'not like', '%Onboarding%')
            ->with('conversation')
            ->orderByDesc('starts_at')
            ->get();
    }

    // ---------------------------------------------------------------- passada 1

    /**
     * Volta à transcrição e copia como a equipe respondeu cada objeção já extraída.
     *
     * @param  Collection<int, Meeting>  $reunioes
     */
    private function lerRespostas(Collection $reunioes): void
    {
        $limite = (int) $this->option('limite');
        $lidas = 0;

        foreach ($reunioes as $m) {
            if ($limite > 0 && $lidas >= $limite) {
                break;
            }

            $pendentes = [];
            foreach (($m->call_review['objecoes'] ?? []) as $i => $o) {
                $texto = trim((string) ($o['objecao'] ?? ''));
                if ($texto === '') {
                    continue;
                }
                if (! $this->option('reler') && array_key_exists('resposta', $o)) {
                    continue;
                }
                $pendentes[$i] = $texto;
            }

            if (! $pendentes) {
                continue;
            }

            $dados = Claude::json(Claude::run($this->promptRespostas($m, $pendentes), 300));
            if (! is_array($dados)) {
                $this->error(sprintf('  #%-4d a IA não devolveu JSON — segue sem as respostas desta call', $m->id));

                continue;
            }

            $review = $m->call_review;
            foreach ($pendentes as $i => $_) {
                $item = $dados[(string) $i] ?? $dados[$i] ?? null;
                $resposta = is_array($item) ? trim((string) ($item['resposta'] ?? '')) : '';
                $funcionou = is_array($item) ? (string) ($item['funcionou'] ?? '') : '';

                $review['objecoes'][$i]['resposta'] = $resposta;
                $review['objecoes'][$i]['funcionou'] = in_array($funcionou, ['sim', 'parcial', 'nao', 'nao_respondida'], true)
                    ? $funcionou
                    : ($resposta === '' ? 'nao_respondida' : 'parcial');
            }

            $m->forceFill(['call_review' => $review])->save();
            $lidas++;
            $this->line(sprintf('  <fg=green>#%-4d</> %d objeção(ões) lida(s)', $m->id, count($pendentes)));
        }

        $this->info("Calls lidas nesta rodada: {$lidas}");
    }

    /** @param  array<int, string>  $pendentes */
    private function promptRespostas(Meeting $m, array $pendentes): string
    {
        $lista = '';
        foreach ($pendentes as $i => $texto) {
            $lista .= "{$i}. {$texto}\n";
        }
        $transcricao = mb_substr((string) $m->transcript, 0, 120000);

        return <<<TXT
        Abaixo está a transcrição REAL de uma call de venda da Bancalize (plataforma de
        pagamentos whitelabel). As falas vêm rotuladas com o nome de quem falou; a equipe da
        Bancalize aparece como "Bancalize Oficial", "Maysa Lima" ou "Guilherme Rodrigues" —
        todos os outros nomes são o LEAD.

        Uma auditoria anterior já extraiu as objeções abaixo. Para CADA UMA, encontre na
        transcrição como a EQUIPE respondeu.

        Regras:
        - "resposta" é CITAÇÃO LITERAL da fala da equipe (pode juntar 2 ou 3 frases seguidas).
          Nunca escreva uma resposta que não está na transcrição. Se a equipe não respondeu,
          devolva resposta vazia.
        - "funcionou" descreve só o que se vê NA CALL, pela reação do lead logo depois:
          "sim" = o lead aceitou e o assunto saiu de cena;
          "parcial" = o lead seguiu, mas voltou ao tema ou ficou em dúvida;
          "nao" = o lead rejeitou ou a objeção travou a conversa;
          "nao_respondida" = a equipe não respondeu.
          Você NÃO sabe o que aconteceu depois da call e não deve tentar adivinhar.

        Responda SOMENTE com este JSON, uma entrada por número da lista, sem texto fora dele:
        {"<numero>": {"resposta": "<citação literal ou vazio>", "funcionou": "sim|parcial|nao|nao_respondida"}}

        OBJEÇÕES:
        {$lista}
        TRANSCRIÇÃO:
        {$transcricao}
        TXT;
    }

    // ---------------------------------------------------------------- passada 2

    /**
     * Agrupa por categoria, escolhe as melhores respostas e grava o contorno na base de
     * conhecimento da empresa dona das calls.
     *
     * @param  Collection<int, Meeting>  $reunioes
     */
    private function construirConteudo(Collection $reunioes): void
    {
        $porCategoria = [];
        $empresa = null;

        foreach ($reunioes as $m) {
            $empresa ??= $m->company_id;
            $avancou = in_array((string) $m->conversation?->stage, self::AVANCOU, true);

            foreach (($m->call_review['objecoes'] ?? []) as $o) {
                $cat = trim((string) ($o['categoria'] ?? ''));
                if ($cat === '') {
                    continue;
                }
                $porCategoria[$cat][] = [
                    'meeting' => $m->id,
                    'company' => $m->company_id,
                    'objecao' => trim((string) ($o['objecao'] ?? '')),
                    'resposta' => trim((string) ($o['resposta'] ?? '')),
                    'funcionou' => (string) ($o['funcionou'] ?? ''),
                    'avancou' => $avancou,
                    'nota' => (float) ($m->call_review['nota_conducao'] ?? 0),
                ];
            }
        }

        if (! $porCategoria) {
            $this->warn('Nenhuma objeção classificada — rode meetings:objecoes antes.');

            return;
        }

        uasort($porCategoria, fn ($a, $b) => count($b) <=> count($a));

        $minCasos = (int) $this->option('min-casos');
        $linhas = [];

        foreach ($porCategoria as $cat => $casos) {
            $boas = $this->melhoresRespostas($casos);
            $estado = match (true) {
                count($casos) < $minCasos => 'pouco caso',
                $boas->isEmpty() => 'BURACO: ninguém soube responder',
                $this->option('so-relatorio') => 'pronto para virar conteúdo',
                default => $this->gravarContorno($cat, $casos, $boas, $casos[0]['company'] ?? $empresa),
            };

            $linhas[] = [
                $cat,
                count($casos),
                collect($casos)->where('funcionou', 'nao_respondida')->count(),
                $boas->count(),
                $estado,
            ];
        }

        $this->newLine();
        $this->info('== CONTORNO DE OBJEÇÃO POR CATEGORIA ==');
        $this->table(['categoria', 'casos', 'sem resposta', 'respostas boas', 'estado'], $linhas);
    }

    /**
     * Ordena as respostas utilizáveis: quem respondeu bem numa call em que o lead avançou
     * ensina mais do que quem respondeu bem numa call que morreu.
     *
     * @param  list<array<string, mixed>>  $casos
     * @return Collection<int, array<string, mixed>>
     */
    private function melhoresRespostas(array $casos): Collection
    {
        return collect($casos)
            ->filter(fn ($c) => $c['resposta'] !== '' && in_array($c['funcionou'], ['sim', 'parcial'], true))
            ->sortByDesc(fn ($c) => ($c['funcionou'] === 'sim' ? 100 : 0) + ($c['avancou'] ? 50 : 0) + $c['nota'])
            ->take(6)
            ->values();
    }

    /**
     * @param  list<array<string, mixed>>  $casos
     * @param  Collection<int, array<string, mixed>>  $boas
     */
    private function gravarContorno(string $cat, array $casos, Collection $boas, ?int $companyId): string
    {
        $citacoes = '';
        foreach ($boas as $k => $c) {
            $marca = $c['avancou'] ? ' (o lead avançou no funil depois desta call)' : '';
            $citacoes .= sprintf("%d. OBJEÇÃO: %s\n   RESPOSTA DA EQUIPE%s: \"%s\"\n", $k + 1, $c['objecao'], $marca, $c['resposta']);
        }

        $dados = Claude::json(Claude::run(<<<TXT
        Abaixo estão objeções REAIS de leads da Bancalize (plataforma de pagamentos whitelabel)
        de uma mesma categoria, com a resposta LITERAL que a equipe deu em cada call.

        Escreva um conhecimento para a IA que atende esses leads no WhatsApp usar quando a
        mesma objeção aparecer.

        Regras duras:
        - Use SOMENTE argumentos e fatos que aparecem nas respostas abaixo. Não acrescente
          número, prazo, garantia, integração ou nome de parceiro que não esteja ali.
        - Escreva como a equipe fala, em português do Brasil, direto, sem jargão de vendas e
          sem prometer o que não foi prometido.
        - De 3 a 6 linhas curtas. É material de consulta para a IA, não uma mensagem pronta
          para enviar ao cliente.

        Responda SOMENTE com este JSON, sem texto fora dele:
        {"gatilho": "<o tema da objeção em até 8 palavras>",
         "conteudo": "<o contorno, 3 a 6 linhas>",
         "keywords": "<8 a 15 palavras que o cliente usaria, separadas por vírgula>"}

        CATEGORIA: {$cat}

        CASOS:
        {$citacoes}
        TXT, 300));

        if (! is_array($dados) || trim((string) ($dados['conteudo'] ?? '')) === '') {
            return 'a IA não devolveu o contorno';
        }

        $chunk = MemoryChunk::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('origem', "call:{$cat}")
            ->first() ?? new MemoryChunk;

        $chunk->forceFill([
            'company_id' => $companyId,
            'chat_tab_id' => null,   // objeção aparece em qualquer etapa; vale para todos os times.
            'kind' => 'objecao',
            'origem' => "call:{$cat}",
            'gatilho' => mb_substr(trim((string) $dados['gatilho']) ?: $cat, 0, 255),
            'conteudo' => trim((string) $dados['conteudo']),
            'keywords' => mb_substr(trim((string) ($dados['keywords'] ?? '')), 0, 255),
        ])->save();

        return sprintf('conteúdo #%d (%d citações de %d casos)', $chunk->id, $boas->count(), count($casos));
    }
}
