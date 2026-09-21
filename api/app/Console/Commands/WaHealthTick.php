<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\WaAccount;
use App\Support\Evolution;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Vigia de cada número de WhatsApp.
 *
 * POR QUE EXISTE: em 19/09/2026 o número oficial — o que recebe os anúncios — parou de
 * receber mensagem. Ninguém soube por dois dias. O painel de saúde já existia, mas era
 * PULL: o `state` da conta só era atualizado quando alguém abria a tela de admin, e só
 * super-admin abre. Dinheiro de anúncio continuou saindo o tempo todo.
 *
 * O QUE VIGIA, por número:
 *   1. conexão caída (só para Evolution — a API oficial não tem "conexão" para cair)
 *   2. número que SEMPRE recebe e ficou mudo numa faixa de horário que costuma ter movimento
 *   3. mensagem saindo com erro acima do normal (entregabilidade)
 *
 * COMO AVISA: abre uma tarefa de prioridade alta no quadro, que é o mesmo caminho que o
 * MeetingsNoShowTick e o DealsWatchdogTick já usam — o dono do negócio vê no lugar onde
 * ele já olha, sem canal novo para configurar. Uma tarefa por problema, não uma a cada
 * 5 minutos: enquanto a tarefa estiver aberta, o alarme não se repete.
 */
class WaHealthTick extends Command
{
    protected $signature = 'wa:health-tick {--verbose-saida : imprime o diagnóstico de cada número}';

    protected $description = 'Vigia conexão, entrada e entregabilidade de cada número de WhatsApp';

    /** Silêncio a partir do qual um número movimentado vira alarme. */
    private const HORAS_MUDO = 6;

    /** Percentual de falha de envio que vira alarme. */
    private const TETO_ERRO = 5.0;

    public function handle(Tenancy $tenancy): int
    {
        foreach (WaAccount::withoutGlobalScopes()->where('is_active', true)->get() as $conta) {
            $tenancy->run($conta->company_id, fn () => $this->verificar($conta));
        }

        return self::SUCCESS;
    }

    private function verificar(WaAccount $conta): void
    {
        $problemas = [];

        // --- 1. conexão (só Evolution; na Cloud API da Meta não existe sessão para cair) ---
        if ($conta->provider === 'evolution' && $conta->instance) {
            $estado = Evolution::connectionState($conta->instance);
            if ($estado !== $conta->state) {
                $conta->state = $estado;
                $conta->state_changed_at = now();
            }
            if ($estado !== 'open') {
                $desde = $conta->state_changed_at?->diffForHumans() ?? 'agora';
                $problemas[] = "a conexão está em `{$estado}` ({$desde}) — o número precisa ler o QR de novo em Admin → WhatsApp.";
            }
        }

        // --- 2. mudez: entrou mensagem nas últimas horas? ---
        $entradas = $this->contar($conta, saida: false, horas: self::HORAS_MUDO);
        $mediaDiaria = $this->mediaDeEntradasPorDia($conta);

        // Só alarma número que TEM movimento habitual: número novo, ou de disparo, fica quieto
        // por dias sem que isso seja defeito.
        if ($mediaDiaria >= 5 && $entradas === 0 && $this->dentroDoHorarioComercial()) {
            $ultima = $this->ultimaEntrada($conta);
            $quando = $ultima ? $ultima->diffForHumans() : 'nunca';
            $problemas[] = "não recebe mensagem há ".self::HORAS_MUDO."h (última entrada: {$quando}), "
                ."mas costuma receber ~".round($mediaDiaria)." por dia. Verifique se o anúncio está entregando "
                ."e se a conta de anúncios tem saldo.";
        }

        // --- 3. entregabilidade: proporção de saída com erro nas últimas 2h ---
        $saidas = $this->contar($conta, saida: true, horas: 2);
        if ($saidas >= 10) {
            $erros = $this->contarErros($conta, horas: 2);
            $pct = round($erros * 100 / max($saidas, 1), 1);
            if ($pct > self::TETO_ERRO) {
                $problemas[] = "{$pct}% das mensagens enviadas nas últimas 2h falharam ({$erros} de {$saidas}).";
            }
        }

        $conta->health_checked_at = now();
        $conta->save();

        if ($this->option('verbose-saida')) {
            $this->line(sprintf('#%-3d %-22s entradas(%dh)=%-4d média/dia=%-5.1f %s',
                $conta->id, $conta->name ?? $conta->phone, self::HORAS_MUDO, $entradas, $mediaDiaria,
                $problemas ? 'PROBLEMA' : 'ok'));
        }

        if ($problemas) {
            $this->alertar($conta, $problemas);
        }
    }

    /** Mensagens de entrada (ou saída) da conta nas últimas N horas. */
    private function contar(WaAccount $conta, bool $saida, int $horas): int
    {
        return Message::whereIn('conversation_id', $this->conversas($conta))
            ->where('is_out', $saida)
            ->where('ts', '>=', now()->subHours($horas)->timestamp)
            ->count();
    }

    private function contarErros(WaAccount $conta, int $horas): int
    {
        return Message::whereIn('conversation_id', $this->conversas($conta))
            ->where('is_out', true)
            ->where('status', 'error')
            ->where('ts', '>=', now()->subHours($horas)->timestamp)
            ->count();
    }

    private function conversas(WaAccount $conta)
    {
        return Conversation::where('wa_account_id', $conta->id)->select('id');
    }

    /** Média de entradas por dia nos últimos 7 dias — a régua do que é "normal" para ESTE número. */
    private function mediaDeEntradasPorDia(WaAccount $conta): float
    {
        $total = Message::whereIn('conversation_id', $this->conversas($conta))
            ->where('is_out', false)
            ->where('ts', '>=', now()->subDays(7)->timestamp)
            ->count();

        return $total / 7;
    }

    private function ultimaEntrada(WaAccount $conta): ?\Illuminate\Support\Carbon
    {
        $ts = Message::whereIn('conversation_id', $this->conversas($conta))
            ->where('is_out', false)->max('ts');

        return $ts ? \Illuminate\Support\Carbon::createFromTimestamp($ts) : null;
    }

    /**
     * Fora do horário comercial ninguém escreve mesmo — alarmar às 3h da manhã só ensina
     * a equipe a ignorar alarme.
     */
    private function dentroDoHorarioComercial(): bool
    {
        $agora = now();

        return $agora->isWeekday() && $agora->hour >= 10 && $agora->hour < 19;
    }

    /** Abre UMA tarefa por número enquanto o problema durar. */
    private function alertar(WaAccount $conta, array $problemas): void
    {
        $titulo = '📵 Número com problema — '.($conta->name ?: $conta->phone);

        if (Task::where('title', $titulo)->where('column', '!=', 'done')->exists()) {
            return;
        }

        Task::create([
            'title' => $titulo,
            'description' => "Número: {$conta->phone} ({$conta->provider})\n\n- ".implode("\n- ", $problemas),
            'type' => 'suporte',
            'priority' => 'alta',
            'column' => 'todo',
            'position' => (Task::where('column', 'todo')->min('position') ?? 0) - 1,
        ]);

        $conta->alerted_at = now();
        $conta->save();

        $this->warn("ALERTA aberto para {$conta->phone}: ".implode(' | ', $problemas));
    }
}
