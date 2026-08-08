<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Conversation;
use App\Support\Claude;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pré-triagem dos leads pela IA: marca `qualified` com um palpite e o porquê.
 *
 * POR QUE ISTO EXISTE: o custo por lead qualificado só serve para decidir orçamento se
 * a triagem acompanhar o gasto. Feita à mão, ela chega dias depois — e nesse meio-tempo
 * o dinheiro já foi para o criativo errado. Aqui a IA marca em minutos e quem atende
 * confirma ou corrige em um clique.
 *
 * O PALPITE NUNCA GANHA DE GENTE: só toca em conversa sem triagem (`qualified` NULL) ou
 * em marca que a própria IA pôs (`qualified_auto`) e que recebeu mensagem nova depois.
 * Quem clicou no chip vira `qualified_auto = 0` e fica intocável.
 *
 * "Não dá para julgar" é resposta válida e vira NULL — metade dos leads de anúncio
 * clica e não fala nada, e chutar "ruim" neles inventaria um número que ninguém mediu.
 */
class QualificarTick extends Command
{
    /**
     * 6 por rodada, e não 20: cada julgamento sobe um processo do CLI, um de cada vez.
     * Na primeira execução a fila é o histórico inteiro (centenas de conversas) e, num
     * VPS de 2 vCPU, esvaziá-la depressa deixaria o chat lento para quem está atendendo.
     * A 6 por rodada o acúmulo escoa em algumas horas e o dia a dia (poucos leads novos
     * por rodada) fica instantâneo de qualquer jeito.
     */
    protected $signature = 'leads:qualificar-tick
        {--limite=6 : quantas conversas por rodada}
        {--dias=45 : só conversas criadas nos últimos N dias}';

    protected $description = 'IA faz a pré-triagem dos leads (qualificado / desqualificado)';

    /** Mensagens do lead necessárias para haver o que julgar. */
    private const MIN_MENSAGENS = 1;

    /**
     * Repetições que fazem uma primeira-mensagem virar "texto de botão".
     * 3 é baixo de propósito: falso positivo aqui só tira força de evidência que já era
     * fraca ("bom dia"), enquanto o falso negativo deixa a IA qualificar quem não digitou
     * nada — o erro que esta regra existe para impedir.
     */
    private const MIN_REPETICOES_BOTAO = 3;

    public function handle(Tenancy $tenancy): int
    {
        if (Claude::indisponivel()) {
            $this->warn('IA fora do ar — nada a fazer.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach (Company::where('qualify_enabled', true)->get() as $empresa) {
            $criterio = trim((string) $empresa->qualify_criteria);
            if ($criterio === '') {
                continue; // sem critério não há triagem possível — e chutar seria pior
            }

            $total += $tenancy->run((int) $empresa->id, function () use ($criterio) {
                return $this->triarEmpresa($criterio, (int) $this->option('limite'), (int) $this->option('dias'));
            });
        }

        $this->info("triados: {$total}");

        return self::SUCCESS;
    }

    /** @return int quantas conversas foram marcadas */
    private function triarEmpresa(string $criterio, int $limite, int $dias = 45): int
    {
        $pendentes = Conversation::query()
            ->where('archived', false)
            // Por padrão só os últimos 45 dias: lead antigo já foi triado na mão ou não
            // importa mais para a DECISÃO DE VERBA, que é o que o painel de marketing usa.
            //
            // Mas a triagem também serve para decidir A QUEM VALE FALAR DE NOVO, e aí o
            // corte atrapalha: em 08/08/2026 havia 261 conversas do número antigo (que
            // saiu do ar) nunca julgadas só por estarem fora da janela — gente que ficou
            // invisível para qualquer reativação. Por isso o prazo virou opção: o
            // agendador segue com 45, e um mutirão passa `--dias` maior.
            ->where('created_at', '>=', now()->subDays(max(1, $dias)))
            ->where(function ($q) {
                // Nunca olhada por ninguém. O `qualified_auto = 0` é o que impede o laço:
                // quando a IA responde "não dá para julgar", a conversa CONTINUA com
                // qualified NULL — só que agora com auto = 1. Sem esta condição ela
                // voltaria para a fila na rodada seguinte, e as mesmas conversas mudas
                // seriam re-julgadas para sempre, queimando chamadas e travando o resto
                // do histórico atrás delas.
                $q->where(fn ($s) => $s->whereNull('qualified')->where('qualified_auto', false))
                    // Marca da IA que envelheceu: chegou mensagem nova depois dela, e a
                    // conversa pode ter mudado de figura (o "quero empréstimo" que depois
                    // conta que tem uma operação rodando). Vale inclusive para o veredito
                    // "não deu para julgar" — é justamente quem costuma falar depois.
                    //
                    // COMPARA COM `qualified_at`, NUNCA COM `updated_at`: quem grava
                    // `last_message_at` grava pelo Eloquent, que carimba `updated_at` no
                    // mesmo UPDATE. As duas sobem juntas, então `last_message_at >
                    // updated_at` era falso sempre e o palpite da primeira mensagem
                    // congelava para sempre — inclusive em lead que depois marcou reunião.
                    // `qualified_at` só é escrito aqui, então ninguém o empurra à frente.
                    ->orWhere(fn ($s) => $s->where('qualified_auto', true)
                        ->where(fn ($t) => $t->whereNull('qualified_at')
                            ->orWhereColumn('last_message_at', '>', 'qualified_at')));
            })
            ->whereHas('messages', fn ($q) => $q->where('is_out', false), '>=', self::MIN_MENSAGENS)
            ->orderByDesc('last_message_at')
            ->limit(max(1, $limite))
            ->get();

        $marcados = 0;
        $botoes = $this->textosDeBotao();

        foreach ($pendentes as $conv) {
            $veredito = $this->julgar($conv, $criterio, $botoes);
            if ($veredito === null) {
                continue; // IA fora ou resposta ilegível — tenta de novo na próxima rodada
            }

            [$qualificado, $motivo] = $veredito;

            $conv->forceFill([
                'qualified' => $qualificado,
                'qualified_reason' => $motivo,
                'qualified_auto' => true,
                // Carimbo do julgamento, e o único lugar que escreve esta coluna. É ele
                // que faz "chegou mensagem depois que eu julguei" ser uma pergunta
                // respondível — ver o comentário da fila em triarEmpresa().
                'qualified_at' => now(),
            ])->save();

            $marcados++;
        }

        return $marcados;
    }

    /**
     * As frases que o Facebook já deixa digitadas no botão do anúncio ou da página.
     *
     * POR QUE ISTO EXISTE: o texto do botão da página da Bancalize dizia "tenho interesse
     * na solução de pagamentos white-label", e a IA leu isso como intenção declarada do
     * lead — qualificou gente que não tinha digitado uma palavra (auditoria de 06/08/2026,
     * conversa 2078). A frase é da empresa, não dele.
     *
     * DESCOBERTA POR DADOS, NÃO CHUMBADA: uma primeira-mensagem que se repete em muitas
     * conversas diferentes só pode ser template — ninguém escreve a mesma frase 88 vezes.
     * Assim isto continua valendo quando o anúncio mudar de texto, e vale para qualquer
     * empresa do CRM sem alguém precisar cadastrar frase nenhuma.
     *
     * Saudação repetida ("bom dia") também cai aqui, e tudo bem: ela igualmente não é
     * evidência de nada.
     *
     * @return list<string> textos normalizados
     */
    private function textosDeBotao(): array
    {
        $empresa = app(Tenancy::class)->id();
        if ($empresa === null) {
            return [];
        }

        return DB::table('messages as m')
            ->join(DB::raw('(SELECT conversation_id, MIN(ts) mts FROM messages WHERE is_out = 0 GROUP BY conversation_id) f'),
                function ($j) {
                    $j->on('f.conversation_id', '=', 'm.conversation_id')->on('f.mts', '=', 'm.ts');
                })
            ->where('m.is_out', false)
            ->where('m.company_id', $empresa)
            ->where('m.text', '<>', '')
            ->groupBy('m.text')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_REPETICOES_BOTAO])
            ->pluck('m.text')
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->all();
    }

    /**
     * Pergunta o veredito à IA.
     *
     * @param  list<string>  $botoes  frases de botão, para não virarem evidência
     * @return array{0: ?bool, 1: string}|null [qualificado, motivo] — null quando a IA falhou
     */
    private function julgar(Conversation $conv, string $criterio, array $botoes = []): ?array
    {
        $linhas = $conv->messages()
            ->reorder()
            ->orderBy('ts')
            ->limit(40)
            ->get(['is_out', 'text', 'transcript'])
            ->map(function ($m) use ($botoes) {
                $t = trim((string) ($m->text ?: $m->transcript));
                if ($t === '') {
                    return null;
                }
                if ($m->is_out) {
                    return 'ATENDENTE: '.mb_substr($t, 0, 400);
                }
                // Marcar em vez de esconder: a IA precisa ver que o lead clicou no
                // anúncio (é o contexto da conversa) sabendo que ele não escreveu aquilo.
                $rotulo = in_array(mb_strtolower($t), $botoes, true) ? 'LEAD [texto do botão]: ' : 'LEAD: ';

                return $rotulo.mb_substr($t, 0, 400);
            })
            ->filter()
            ->implode("\n");

        // Nada legível: a conversa só tem mídia sem texto nem transcrição. NÃO devolve
        // `null` aqui — `null` significa "a IA falhou, tenta de novo", e o laço não grava
        // nada, então a conversa voltava ao topo da fila PARA SEMPRE (é por
        // `last_message_at DESC`). Eram as conversas 708 e 1445 queimando 2 das 6 vagas de
        // cada rodada desde julho, e foi o que fez o mutirão de 08/08 cair de 4 para 2
        // julgamentos por lote.
        //
        // O veredito certo é abstenção COM carimbo: some da fila e só volta se chegar
        // mensagem nova — que é exatamente a regra de reentrada que já existe.
        if (trim($linhas) === '') {
            return [null, 'Só mídia sem texto nem transcrição — não havia o que julgar.'];
        }

        $prompt = <<<TXT
        Você faz a triagem de leads que chegaram por anúncio no WhatsApp.

        CRITÉRIO DA EMPRESA:
        {$criterio}

        CONVERSA:
        {$linhas}

        Responda SOMENTE um JSON, sem texto em volta:
        {"qualificado": true|false|null, "motivo": "no máximo 90 caracteres, em português"}

        Use true quando o lead se encaixa no critério de qualificado.
        Use false quando ele se encaixa no critério de desqualificado.

        Um desqualificador que o próprio lead declarou JÁ BASTA para false — não espere
        que ele descreva o negócio dele. Quem escreve "quero um empréstimo" já disse o
        que veio buscar; cobrar mais contexto antes de decidir deixaria de fora
        justamente o lead ruim mais fácil de identificar.
        Use null quando o lead não disse nada que permita julgar — é o caso de quem só
        mandou a mensagem automática do anúncio, respondeu "oi"/"sim" ou sumiu. Não
        adivinhe: null é a resposta certa quando falta informação, e é melhor que um
        palpite, porque alguém vai decidir orçamento com esse número.

        LINHA MARCADA "LEAD [texto do botão]" NÃO É EVIDÊNCIA DE NADA: é a frase que o
        Facebook já deixa digitada no botão do anúncio ou da página, escrita pela própria
        empresa. Algumas dessas frases citam o produto ("tenho interesse na solução de
        pagamentos white-label") e parecem uma declaração de interesse — não são. O lead
        só apertou um botão. Se tudo que ele tem é linha de botão, a resposta é null.

        O motivo deve citar o que o LEAD digitou, não o texto do botão nem o que o
        atendente respondeu.
        TXT;

        $json = Claude::json(Claude::run($prompt, 90));
        if (! is_array($json) || ! array_key_exists('qualificado', $json)) {
            return null;
        }

        $q = $json['qualificado'];
        if (! is_bool($q) && $q !== null) {
            return null;
        }

        $motivo = trim((string) ($json['motivo'] ?? ''));

        return [$q, mb_substr($motivo, 0, 180)];
    }
}
