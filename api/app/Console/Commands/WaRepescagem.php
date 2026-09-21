<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\StageAutomationRun;
use App\Services\ChatSender;
use App\Support\Evolution;
use App\Support\OptOut;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repescagem dos leads que ficaram sem resposta enquanto o número oficial esteve travado.
 *
 * Em 14/08/2026 a Meta travou a conta (erro 131031) e recusou 72 respostas da IA em 33
 * conversas: o CRM mostra a bolha enviada, o cliente nunca recebeu nada. Quando a conta
 * voltou, quase todas essas conversas já estavam fora da janela de 24h — texto livre é
 * recusado, só template aprovado reabre o canal. Daí este comando, e não simplesmente
 * religar o auto-reply.
 *
 * Ele manda UMA mensagem por conversa, espaçada, com um template UTILITY
 * (`pendencia_nossa` — "ficou uma pendência da nossa parte"), que é o que de fato
 * aconteceu. Nada de MARKETING: a punição foi por spam e o histórico ainda está fresco.
 *
 * Padrão é DRY-RUN: sem `--enviar` só imprime a lista com o texto exato que sairia.
 */
class WaRepescagem extends Command
{
    protected $signature = 'wa:repescagem
        {--desde=2026-08-14 08:00 : início do apagão (hora local) — janela de busca das falhas}
        {--conta=11 : id da wa_account (número oficial) a repescar}
        {--template=pendencia_nossa : template aprovado a usar}
        {--intervalo=90 : segundos entre um envio e outro}
        {--pular= : ids de conversa a ignorar, separados por vírgula}
        {--so= : roda SÓ nestes ids de conversa (separados por vírgula)}
        {--limite=0 : para depois de N envios (0 = sem limite)}
        {--enviar : envia de verdade (sem esta flag é simulação)}';

    protected $description = 'Reabre por template as conversas que ficaram sem resposta enquanto o número oficial esteve travado';

    /** Assunto do {{2}} do template, por etapa do funil. */
    private const ASSUNTO_QUENTE = 'a proposta que conversamos';

    private const ASSUNTO_PADRAO = 'a plataforma de pagamentos com a sua marca';

    private const ETAPAS_QUENTES = ['proposta', 'reuniao-realizada', 'disse-que-vai-fechar', 'fechado'];

    public function handle(ChatSender $sender): int
    {
        $enviar = (bool) $this->option('enviar');
        $contaId = (int) $this->option('conta');
        $template = (string) $this->option('template');
        $intervalo = max(0, (int) $this->option('intervalo'));
        $limite = (int) $this->option('limite');
        $desde = strtotime((string) $this->option('desde'));
        $pular = $this->ids($this->option('pular'));
        $so = $this->ids($this->option('so'));

        if (! $desde) {
            $this->error('--desde inválido');

            return self::FAILURE;
        }

        $candidatas = $so ?: $this->candidatas($contaId, $desde);
        $candidatas = array_values(array_diff($candidatas, $pular));

        if (! $candidatas) {
            $this->info('Nada a repescar.');

            return self::SUCCESS;
        }

        $tenancy = app(Tenancy::class);
        $fila = [];

        foreach (Conversation::whereIn('id', $candidatas)->get() as $conv) {
            if ($motivo = $this->motivoParaPular($conv, $contaId, $so !== [])) {
                $this->line(sprintf('  <fg=gray>pulada %-5s %-24s %s</>', $conv->id, $this->corta($conv->name, 24), $motivo));

                continue;
            }
            $fila[] = $conv;
        }

        $this->newLine();
        $this->info(sprintf('%d conversa(s) para repescar%s — template "%s", 1 a cada %ds.',
            count($fila), $enviar ? '' : ' (SIMULAÇÃO)', $template, $intervalo));
        $this->newLine();

        $enviados = 0;
        $falhas = 0;

        foreach ($fila as $i => $conv) {
            if ($limite > 0 && $enviados >= $limite) {
                $this->warn("Limite de {$limite} envios atingido — parando.");
                break;
            }

            $params = [$this->primeiroNome($conv->name), $this->assunto($conv)];
            $espelho = $this->render($params);

            $this->line(sprintf('%2d. <options=bold>%-5s %-24s</> %-14s %s',
                $i + 1, $conv->id, $this->corta($conv->name, 24), $conv->stage, $espelho));

            if (! $enviar) {
                continue;
            }

            // Trava de segurança: se a Meta voltou a recusar (recibo `failed` chega em
            // segundos, e a esta altura os envios anteriores já têm resposta), para tudo.
            // Insistir com a conta travada foi exatamente o que gerou as 72 bolhas falsas.
            if ($enviados >= 2 && $this->recibosFalhando()) {
                $this->error('Recibo de falha detectado nos envios desta rodada — repescagem abortada.');
                Evolution::log('repescagem.abortada', ['enviados' => $enviados], 'warning');

                return self::FAILURE;
            }

            $tenancy->set($conv->company_id);

            $msg = null;
            try {
                $msg = $sender->template($conv, $template, 'pt_BR', $params, $espelho);
            } catch (\Throwable $e) {
                $this->error('    erro: '.$e->getMessage());
            }

            if (! $msg) {
                $falhas++;
                $this->error('    a Meta recusou o envio — conversa segue sem resposta.');
                Evolution::log('repescagem.recusada', ['conversation_id' => $conv->id], 'warning');

                continue;
            }

            // Marca a bolha: é o que torna o comando repetível sem mandar duas vezes
            // para a mesma pessoa (e o que o time vê no histórico como "isto foi repescagem").
            $msg->update(['meta' => 'repescagem']);
            $enviados++;
            Evolution::log('repescagem.enviada', [
                'conversation_id' => $conv->id,
                'message_id' => $msg->id,
                'template' => $template,
            ]);

            if ($intervalo > 0 && $i < count($fila) - 1) {
                // Jitter: um envio a cada 90s cravados é assinatura de robô. ±25%.
                sleep((int) round($intervalo * (0.75 + (random_int(0, 50) / 100))));
            }
        }

        $this->newLine();
        $enviar
            ? $this->info("Repescagem concluída: {$enviados} enviada(s), {$falhas} recusada(s).")
            : $this->comment('Simulação — nada foi enviado. Repita com --enviar.');

        return self::SUCCESS;
    }

    /**
     * Quem entra na repescagem (união de três origens, todas do número travado):
     *  1. conversas com resposta NOSSA recusada pela Meta (status=error) desde o apagão;
     *  2. conversas com playbook de etapa pendente (a proposta pós-reunião que não saiu);
     *  3. conversas em que o cliente escreveu depois do apagão e ninguém respondeu.
     *
     * @return array<int>
     */
    private function candidatas(int $contaId, int $desde): array
    {
        $falhas = Message::query()->withoutGlobalScopes()
            ->where('is_out', true)->where('status', 'error')->where('ts', '>=', $desde)
            ->distinct()->pluck('conversation_id')->all();

        $playbooks = StageAutomationRun::query()->withoutGlobalScopes()
            ->where('status', 'pending')->distinct()->pluck('conversation_id')->all();

        // Última mensagem da conversa é do cliente = ficou no vácuo.
        $vacuo = DB::table('conversations as c')
            ->join(DB::raw('(SELECT conversation_id, MAX(ts) mx FROM messages WHERE ts >= '.$desde.' GROUP BY conversation_id) m'),
                'm.conversation_id', '=', 'c.id')
            ->join('messages as ult', function ($j) {
                $j->on('ult.conversation_id', '=', 'c.id')->on('ult.ts', '=', 'm.mx');
            })
            ->where('ult.is_out', false)
            ->distinct()->pluck('c.id')->all();

        $ids = array_unique(array_merge($falhas, $playbooks, $vacuo));

        return Conversation::whereIn('id', $ids)->where('wa_account_id', $contaId)->pluck('id')->all();
    }

    /** Motivo para NÃO mandar (null = manda). */
    private function motivoParaPular(Conversation $conv, int $contaId, bool $forcado): ?string
    {
        if (! $conv->phone) {
            return 'sem telefone';
        }
        if ((int) $conv->wa_account_id !== $contaId && ! $forcado) {
            return 'não é do número oficial';
        }
        if ($conv->archived) {
            return 'arquivada';
        }
        // Cliente que pediu para parar não recebe repescagem — insistir com ele é a
        // definição do que travou a conta.
        $entradas = $conv->messages()->where('is_out', false)
            ->reorder()->orderByDesc('ts')->take(5)->pluck('text');
        foreach ($entradas as $texto) {
            if (OptOut::pediuParar($texto)) {
                return 'cliente pediu para parar';
            }
        }
        if ($conv->messages()->where('is_out', true)->where('meta', 'repescagem')->exists()) {
            return 'já repescada';
        }
        // Janela aberta E resposta ainda fresca: quem responde é o atendimento normal,
        // com contexto — melhor que um template genérico. Mas o auto-reply desiste de
        // pendência velha (`stale_hours`, para não responder o lead horas depois), e é
        // justamente o caso de quem escreveu durante o apagão: aí a repescagem assume.
        $ultimaEntrada = $conv->lastInboundTs();
        $stale = (int) config('services.auto_reply.stale_hours', 6) * 3600;
        if ($conv->canSendFreeform() && $ultimaEntrada && (time() - $ultimaEntrada) <= $stale) {
            return 'janela de 24h aberta e recente — o auto-reply responde';
        }
        // Já chegou alguma coisa nossa depois da última mensagem dele: não ficou no vácuo.
        $chegouAlgo = $conv->messages()->where('is_out', true)
            ->whereRaw("COALESCE(status, '') != 'error'")
            ->when($ultimaEntrada, fn ($q) => $q->where('ts', '>', $ultimaEntrada))
            ->exists();
        if ($chegouAlgo && $conv->stage && ! in_array($conv->stage, self::ETAPAS_QUENTES, true)) {
            return 'já recebeu resposta depois da última mensagem dele';
        }

        return null;
    }

    /** Algum envio desta rodada voltou com recibo de falha? */
    private function recibosFalhando(): bool
    {
        return Message::query()->withoutGlobalScopes()
            ->where('meta', 'repescagem')->where('status', 'error')
            ->where('ts', '>=', time() - 3600)
            ->exists();
    }

    private function assunto(Conversation $conv): string
    {
        return in_array((string) $conv->stage, self::ETAPAS_QUENTES, true)
            ? self::ASSUNTO_QUENTE
            : self::ASSUNTO_PADRAO;
    }

    /**
     * Primeiro nome utilizável no {{1}}. Metade da base entra pelo anúncio com o nome do
     * WhatsApp ("clebersantos00733", "Zoonose 🐶"), e "Oi, clebersantos00733!" é pior que
     * não personalizar — nesses casos vira "Oi, tudo bem!".
     */
    private function primeiroNome(?string $nome): string
    {
        $bruto = trim(explode(' ', trim((string) $nome))[0] ?? '');
        $limpo = trim((string) preg_replace('/[^\p{L}\'-]/u', '', $bruto));

        // Apelido de WhatsApp com número no meio ("clebersantos00733") não é nome de
        // gente: tratar como se fosse gera "Oi, Clebersantos!".
        $temDigito = (bool) preg_match('/\d/', $bruto);

        // Primeira palavra que não identifica ninguém — nome de empresa, cargo ou
        // devoção. "Oi, Filha!" e "Oi, Grupo!" soam pior que não personalizar.
        $generico = ['grupo', 'empresa', 'comercial', 'financeiro', 'atendimento', 'contato',
            'filha', 'filho', 'casa', 'loja', 'adm', 'dr', 'dra', 'sr', 'sra', 'me', 'meu', 'minha'];

        if ($temDigito || mb_strlen($limpo) < 3 || in_array(mb_strtolower($limpo), $generico, true)) {
            return 'tudo bem';
        }

        return mb_convert_case(mb_strtolower($limpo), MB_CASE_TITLE, 'UTF-8');
    }

    /** O corpo do template com as variáveis trocadas — o que o cliente vai ler. */
    private function render(array $params): string
    {
        return sprintf(
            'Oi, %s! Ficou uma pendência da nossa parte sobre %s e eu não quis te deixar sem resposta. Posso te atualizar por aqui?',
            $params[0], $params[1]
        );
    }

    private function corta(?string $t, int $n): string
    {
        $t = trim((string) $t);

        return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1).'…' : $t;
    }

    /** @return array<int> */
    private function ids($opcao): array
    {
        return collect(explode(',', (string) $opcao))
            ->map(fn ($v) => (int) trim($v))->filter()->unique()->values()->all();
    }
}
