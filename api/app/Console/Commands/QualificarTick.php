<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\LeadActivity;
use App\Models\Meeting;
use App\Services\MeetingScheduler;
use App\Support\Claude;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Quanto tempo uma reprovação da IA precisa ficar de pé, sem mexer na conversa, antes
     * de custar o horário do lead. É a margem contra o veredito que a própria IA desfaz
     * minutos depois — ver desmarcarReprovados().
     */
    private const ESPERA_IA = 30;

    /**
     * O mesmo para a reprovação feita por gente. É curta porque decisão humana não se
     * desfaz sozinha: só precisa cobrir o CICLO do chip, que passa por "desqualificado"
     * a caminho de "sem triagem".
     */
    private const ESPERA_HUMANO = 5;

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

        // O cancelamento NÃO acontece junto com o veredito — ver desmarcarReprovados().
        $this->desmarcarReprovados();

        return $marcados;
    }

    /**
     * Lead reprovado que JÁ tinha horário perde o horário — mas só depois que a reprovação
     * ASSENTA. Este é o ponto delicado da regra inteira.
     *
     * O veredito da IA é revisável e revisado o tempo todo: a fila re-julga toda conversa
     * com marca automática assim que `last_message_at > qualified_at`, e `last_message_at`
     * sobe TAMBÉM quando somos NÓS que mandamos mensagem (ChatSender, nudge, campanha,
     * lembrete de reunião). Ou seja, a própria resposta da IA reabre a triagem do lead.
     *
     * Foi o que aconteceu com a conv 2274 em 09/08/2026: reprovado às 13:42:27, e às
     * 13:45:41 a IA voltou atrás ("não ficou claro se quer estrutura própria ou só captar")
     * — reaberta pela NOSSA mensagem das 13:43:09, não por algo que o lead disse.
     *
     * Cancelar no ato desses 3 minutos seria estrago sem volta: o horário volta para a
     * lista de livres, o próximo lead marca em cima, e quando o veredito se desfaz não há
     * o que reverter — a vaga tem dono novo. E ninguém foi avisado de nada, nem o lead
     * (de propósito) nem o time. O prejuízo cai justamente sobre o lead bom.
     *
     * Por isso só desmarca o que está QUIETO e ESTÁVEL:
     * - reprovado pela IA (humano nunca é re-julgado, mas também nunca chega aqui);
     * - sem nada pendente que reabra a triagem (`last_message_at <= qualified_at`);
     * - com o veredito de pé há pelo menos ESPERA_MINUTOS.
     * Conversa viva não perde horário; ela perde quando esfria reprovada. Como reunião é
     * marcada com dias de antecedência, a espera não custa nada em vaga ocupada à toa.
     */
    private function desmarcarReprovados(): void
    {
        $reprovados = Conversation::query()
            ->where('qualified', false)
            // Sem a hora da decisão não dá para saber se ela assentou. Toda decisão nova
            // é carimbada (aqui e no PATCH do chip); sem carimbo é reprovação antiga, e
            // ela só volta a ser considerada quando alguém mexer no chip de novo.
            ->whereNotNull('qualified_at')
            ->where(function ($q) {
                // MARCA DA IA: veredito volátil, exige silêncio e meia hora de pé.
                $q->where(fn ($s) => $s->where('qualified_auto', true)
                    ->where('qualified_at', '<=', now()->subMinutes(self::ESPERA_IA))
                    // Mensagem depois do veredito = re-julgamento a caminho. Não age sobre
                    // o que ainda vai ser revisto.
                    ->where(fn ($t) => $t->whereNull('last_message_at')
                        ->orWhereColumn('last_message_at', '<=', 'qualified_at')))
                    // MARCA DE GENTE: vale mais que a da IA e não se desfaz sozinha, então
                    // não espera silêncio nenhum — conversa que continua andando não torna
                    // a decisão de quem leu a conversa menos verdadeira. Os poucos minutos
                    // existem só por causa do ciclo do chip (qualificado → desqualificado →
                    // sem triagem): quem está limpando a marca passa por "desqualificado"
                    // no caminho, e um clique de passagem não pode apagar reunião.
                    ->orWhere(fn ($s) => $s->where('qualified_auto', false)
                        ->where('qualified_at', '<=', now()->subMinutes(self::ESPERA_HUMANO)));
            })
            ->whereHas('meetings', fn ($q) => $q->active())
            ->get();

        foreach ($reprovados as $conv) {
            $this->desmarcarReuniao($conv, (string) $conv->qualified_reason, (bool) $conv->qualified_auto);
        }
    }

    /**
     * Apaga o horário de UM lead reprovado, EM SILÊNCIO — de propósito: avisar convida o
     * lead a pedir outro horário, e cada ida e volta dessas é resposta da IA gerada para
     * uma conversa já reprovada. O registro fica na ficha, e é lá que o time decide se
     * vale falar com ele.
     */
    private function desmarcarReuniao(Conversation $conv, string $motivo, bool $daIa): void
    {
        $reuniao = Meeting::activeFor($conv->id);
        if (! $reuniao) {
            return;
        }

        $quando = $reuniao->starts_at?->copy()
            ->setTimezone(config('app.timezone', 'America/Sao_Paulo'))
            ->locale('pt_BR')->isoFormat('dddd, DD/MM [às] HH:mm') ?? 'sem data';

        // Quem reprovou muda o que se lê na ficha meses depois — "a IA achou" e "alguém do
        // time leu e decidiu" não são a mesma informação na hora de revisar um cancelamento.
        $porQuem = $daIa ? 'reprovado pela triagem da IA' : 'reprovado pelo time';

        try {
            app(MeetingScheduler::class)->dropMeeting($reuniao, "lead {$porQuem}");
        } catch (\Throwable $e) {
            Log::warning('triagem: falha ao desmarcar reunião do lead reprovado', [
                'conversation' => $conv->id, 'meeting' => $reuniao->id, 'e' => $e->getMessage(),
            ]);

            return;
        }

        LeadActivity::log(
            $conv->id,
            'reuniao',
            "Reunião de {$quando} desmarcada: lead {$porQuem}",
            ($motivo !== '' ? "Motivo: {$motivo}\n\n" : '')
                .'O lead NÃO foi avisado. Se discordar, mude o chip de qualificação na conversa '
                .'e remarque à mão.',
        );

        $this->warn("triagem: reunião {$reuniao->id} (conversa {$conv->id}) desmarcada — {$porQuem}");
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
