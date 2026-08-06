<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Conversation;
use App\Support\Claude;
use App\Support\Tenancy;
use Illuminate\Console\Command;

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
    protected $signature = 'leads:qualificar-tick {--limite=6 : quantas conversas por rodada}';

    protected $description = 'IA faz a pré-triagem dos leads (qualificado / desqualificado)';

    /** Mensagens do lead necessárias para haver o que julgar. */
    private const MIN_MENSAGENS = 1;

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
                return $this->triarEmpresa($criterio, (int) $this->option('limite'));
            });
        }

        $this->info("triados: {$total}");

        return self::SUCCESS;
    }

    /** @return int quantas conversas foram marcadas */
    private function triarEmpresa(string $criterio, int $limite): int
    {
        $pendentes = Conversation::query()
            ->where('archived', false)
            // Lead antigo já foi triado na mão ou não importa mais para a decisão de verba.
            ->where('created_at', '>=', now()->subDays(45))
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
                    ->orWhere(fn ($s) => $s->where('qualified_auto', true)
                        ->whereColumn('last_message_at', '>', 'updated_at'));
            })
            ->whereHas('messages', fn ($q) => $q->where('is_out', false), '>=', self::MIN_MENSAGENS)
            ->orderByDesc('last_message_at')
            ->limit(max(1, $limite))
            ->get();

        $marcados = 0;

        foreach ($pendentes as $conv) {
            $veredito = $this->julgar($conv, $criterio);
            if ($veredito === null) {
                continue; // IA fora ou resposta ilegível — tenta de novo na próxima rodada
            }

            [$qualificado, $motivo] = $veredito;

            $conv->forceFill([
                'qualified' => $qualificado,
                'qualified_reason' => $motivo,
                'qualified_auto' => true,
            ])->save();

            $marcados++;
        }

        return $marcados;
    }

    /**
     * Pergunta o veredito à IA.
     *
     * @return array{0: ?bool, 1: string}|null [qualificado, motivo] — null quando a IA falhou
     */
    private function julgar(Conversation $conv, string $criterio): ?array
    {
        $linhas = $conv->messages()
            ->reorder()
            ->orderBy('ts')
            ->limit(40)
            ->get(['is_out', 'text', 'transcript'])
            ->map(function ($m) {
                $t = trim((string) ($m->text ?: $m->transcript));

                return $t === '' ? null : ($m->is_out ? 'ATENDENTE: ' : 'LEAD: ').mb_substr($t, 0, 400);
            })
            ->filter()
            ->implode("\n");

        if (trim($linhas) === '') {
            return null;
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

        O motivo deve citar o que o LEAD disse, não o que o atendente respondeu.
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
