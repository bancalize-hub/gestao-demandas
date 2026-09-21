<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Task;
use App\Support\Evolution;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Cobra os negócios quentes que ficaram sem ninguém.
 *
 * Levantamento de 18/08/2026, nos 16 negócios que passaram por reunião e disseram que
 * iam fechar: 12 sem responsável, 11 sem uma única mensagem sobre preço ou contrato,
 * 4 sem contato nenhum depois da call. Nenhum sistema reclamou — não havia quem
 * reclamasse. O único negócio fechado no período foi o que teve proposta no dia seguinte.
 *
 * Este tick não fala com o cliente: ele cutuca a EQUIPE, criando tarefa no quadro (que é
 * onde o time já trabalha) e registrando no log. Três perguntas, uma por vez:
 *   1. o negócio tem dono?
 *   2. a proposta saiu?
 *   3. alguém falou com ele nos últimos dias?
 */
class DealsWatchdogTick extends Command
{
    protected $signature = 'deals:watchdog-tick
        {--dias=3 : silêncio (em dias) que caracteriza negócio parado}
        {--idade=30 : ignora negócio sem nenhuma atividade há mais de N dias (morto, não quente)}
        {--limite=15 : teto de tarefas por rodada}
        {--horas-proposta=24 : prazo para a proposta sair depois da reunião}
        {--dry-run : só mostra o que faria}';

    protected $description = 'Cria tarefa para negócio quente sem dono, sem proposta ou parado';

    public function handle(): int
    {
        $quentes = (array) config('services.crm_watchdog.stages_quentes', [
            'reuniao-realizada', 'proposta', 'negociacao', 'disse-que-vai-fechar',
        ]);
        $dias = max(1, (int) $this->option('dias'));
        $horas = max(1, (int) $this->option('horas-proposta'));
        $seco = (bool) $this->option('dry-run');
        $idade = max(1, (int) $this->option('idade'));
        $limite = max(1, (int) $this->option('limite'));
        $tenancy = app(Tenancy::class);

        // Consulta global (sem tenant): os negócios quentes de TODAS as empresas.
        // Negócio sem sinal de vida há muito tempo não é quente, é morto — cobrar isso
        // só enche o quadro. E a ordem importa: quem disse que ia fechar vem primeiro,
        // depois quem está mais fresco. Com teto por rodada, o time recebe uma fila que
        // dá para trabalhar em vez de 227 tarefas de uma vez (medido na 1ª simulação).
        // A config lista da etapa mais fria para a mais quente; FIELD() dá 1 ao primeiro,
        // então DESC coloca "disse que vai fechar" no topo da fila.
        $ordem = array_values($quentes);
        $negocios = Conversation::withoutGlobalScopes()
            ->whereIn('stage', $quentes)
            ->where('archived', false)
            ->where(fn ($q) => $q->where('last_message_at', '>=', now()->subDays($idade))
                ->orWhere('updated_at', '>=', now()->subDays($idade)))
            ->orderByRaw('FIELD(stage, "'.implode('","', $ordem).'") DESC')
            ->orderByDesc('last_message_at')
            ->get();

        $criadas = 0;

        foreach ($negocios as $c) {
            $tenancy->set($c->company_id);

            // "Último toque" tem de olhar a conversa inteira, não só o carimbo novo:
            // negócio que já estava parado antes desta funcionalidade existir tem
            // last_touch_at nulo e passaria batido justamente por ser o mais abandonado.
            $ultimo = $c->last_touch_at?->timestamp
                ?? $c->messages()->where('is_out', true)->reorder()->max('ts')
                ?? $c->messages()->reorder()->max('ts');
            $diasParado = $ultimo ? (int) floor((time() - (int) $ultimo) / 86400) : null;

            // Estado do canal: fora da janela de 24h o vendedor NÃO consegue mandar texto
            // livre, só template aprovado. Sem este aviso na tarefa, ele abre o rascunho que
            // a IA escreveu (followup:tick), tenta enviar e toma erro sem entender por quê.
            $janela = $c->canSendFreeform()
                ? ''
                : ' ⚠️ Janela de 24h fechada: só dá para reabrir com template aprovado.';

            $avisos = [];

            if (! $c->owner_user_id) {
                $avisos[] = ['sem-dono', "Definir responsável — {$c->name}",
                    'Negócio quente sem dono. Enquanto ninguém for responsável, ele não é de ninguém.'.$janela];
            }

            $reuniaoFim = $c->meetings()->where('attended', true)->reorder()->max('ends_at');
            if ($reuniaoFim && ! $c->proposal_sent_at
                && now()->diffInHours(\Illuminate\Support\Carbon::parse($reuniaoFim), true) >= $horas) {
                $avisos[] = ['sem-proposta', "Proposta atrasada — {$c->name}",
                    'A reunião aconteceu e nenhuma proposta, orçamento ou documento saiu para este lead.'.$janela];
            }

            if ($diasParado !== null && $diasParado >= $dias) {
                $avisos[] = ['parado', "Negócio parado há {$diasParado} dias — {$c->name}",
                    'Nenhum contato nosso desde então. Retomar ou marcar como perdido — parado não é etapa.'.$janela];
            }

            foreach ($avisos as [$tipo, $titulo, $descricao]) {
                if ($criadas >= $limite) {
                    break 2;
                }
                // Título fixo por conversa e motivo: o tick roda de hora em hora e não pode
                // empilhar a mesma cobrança. "Parado há N dias" muda de título a cada dia,
                // por isso é deduplicado pelo prefixo.
                $prefixo = $tipo === 'parado' ? "Negócio parado há" : $titulo;
                $jaExiste = Task::where('conversation_id', $c->id)
                    ->where('column', '!=', 'done')
                    ->where('title', 'like', $prefixo.'%')
                    ->exists();

                $this->line(sprintf('  %-11s %-26s %s', $tipo, mb_substr((string) $c->name, 0, 26), $jaExiste ? '(já avisado)' : 'NOVA TAREFA'));

                if ($jaExiste) {
                    continue;
                }
                // Na simulação a cobrança também conta para o teto — senão o preview mostra
                // 227 linhas e o run de verdade cria 15, que é o oposto de simular.
                if ($seco) {
                    $criadas++;

                    continue;
                }

                Task::create([
                    'conversation_id' => $c->id,
                    'title' => $titulo,
                    'description' => $descricao,
                    'client' => $c->name,
                    'type' => 'followup',
                    'priority' => 'alta',
                    'column' => 'todo',
                    'position' => (Task::where('column', 'todo')->min('position') ?? 0) - 1,
                    'starts_at' => now(),
                    'due' => now()->addDay(),
                ]);
                $criadas++;

                Evolution::log('watchdog.negocio', [
                    'conversation_id' => $c->id, 'motivo' => $tipo, 'dias_parado' => $diasParado,
                ]);
            }
        }

        $this->newLine();
        $this->info($seco ? 'Simulação — nada criado.' : "Tarefas criadas: {$criadas} (teto {$limite} por rodada)");

        return self::SUCCESS;
    }
}
