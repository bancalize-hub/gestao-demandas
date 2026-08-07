<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Support\Realtime;
use App\Support\Tenancy;
use App\Support\Wa;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Lembrete automático de reunião: avisa o cliente pelo WhatsApp X minutos antes
 * de a reunião começar (padrão 60 min — configurável em services.meeting_reminder).
 * Roda a cada minuto; cada reunião só é lembrada uma vez (reminder_sent_at).
 */
class MeetingReminderTick extends Command
{
    protected $signature = 'meetings:remind-tick';

    protected $description = 'Lembra o cliente pelo WhatsApp da reunião agendada, X minutos antes de começar';

    public function handle(): int
    {
        if (! config('services.meeting_reminder.enabled', true)) {
            return self::SUCCESS;
        }

        // Reuniões ainda por vir, já dentro da janela de lembrete (starts_at <= agora + lead) e
        // que ainda não foram lembradas. O lead é por-reunião (DATE_ADD com a coluna).
        $due = Meeting::query()
            ->whereNull('cancelled_at')   // reunião cancelada/remarcada não gera lembrete
            ->whereNull('reminder_sent_at')
            ->whereNotNull('phone')
            ->where('starts_at', '>', now())
            ->whereRaw('starts_at <= DATE_ADD(?, INTERVAL reminder_lead_minutes MINUTE)', [now()])
            ->with('conversation')
            ->get();

        $tenancy = app(Tenancy::class);

        $this->enviar($due, $tenancy, 'reminder_sent_at', segundo: false);
        $this->enviar($this->devidasSegundoAviso(), $tenancy, 'reminder2_sent_at', segundo: true);

        return self::SUCCESS;
    }

    /**
     * 2º lembrete: "está começando", `second_lead_minutes` antes (padrão 15).
     *
     * Exige o 1º já enviado — e enviado há pelo menos 5 min. Sem essa condição, reunião
     * marcada em cima da hora cairia nas duas janelas no MESMO tick e o lead levaria duas
     * mensagens seguidas.
     */
    private function devidasSegundoAviso()
    {
        $lead = (int) config('services.meeting_reminder.second_lead_minutes', 15);
        if ($lead <= 0) {
            return collect();   // segundo aviso desligado
        }

        return Meeting::query()
            ->whereNull('cancelled_at')
            ->whereNull('reminder2_sent_at')
            ->whereNotNull('reminder_sent_at')
            ->where('reminder_sent_at', '<=', now()->subMinutes(5))
            ->whereNotNull('phone')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addMinutes($lead))
            ->with('conversation')
            ->get();
    }

    /**
     * Manda um lote e carimba a coluna de controle da rodada.
     *
     * @param  iterable<Meeting>  $due
     */
    private function enviar(iterable $due, Tenancy $tenancy, string $coluna, bool $segundo): void
    {
        foreach ($due as $meeting) {
            // Contexto da empresa dona da reunião: o envio usa o WhatsApp dela e a
            // mensagem espelhada nasce carimbada.
            $tenancy->set((int) $meeting->company_id);

            try {
                $parts = $this->messageParts($meeting, $segundo);
                $text = $this->buildMessage($parts, $segundo);

                $channel = $meeting->conversation ? Wa::forConversation($meeting->conversation) : Wa::primary();

                // Na API oficial o lembrete quase sempre cai fora da janela de 24h (a reunião
                // foi marcada dias antes), e texto livre seria recusado — silenciosamente, já
                // que o tick só reclama no log. Nesse caso vai por template aprovado, que é o
                // caminho que a Meta oferece para avisar o cliente fora da janela.
                $waId = $channel->canSendFreeform($meeting->conversation?->lastInboundTs())
                    ? $channel->sendText($meeting->phone, $text)
                    : $channel->sendTemplate(
                        $meeting->phone,
                        (string) config('services.meeting_reminder.template'),
                        (string) config('services.meeting_reminder.template_language', 'pt_BR'),
                        [$parts['nome'], $parts['quando'], $parts['link']],
                    );

                if ($waId === null) {
                    $this->warn("lembrete: falha ao enviar reunião {$meeting->id} ({$meeting->phone})");

                    continue; // tenta de novo no próximo tick (a coluna de controle continua null)
                }

                $meeting->update([$coluna => now()]);

                // Espelha o lembrete na conversa (igual ao auto-reply), p/ aparecer no chat.
                if ($conv = $meeting->conversation) {
                    $ts = time();
                    $data = [
                        'type' => 'text',
                        'is_out' => true,
                        'text' => mb_substr($text, 0, 4000),
                        'time' => date('H:i', $ts),
                        'ts' => $ts,
                        'position' => ((int) $conv->messages()->max('position')) + 1,
                    ];
                    $msg = $waId !== ''
                        ? $conv->messages()->updateOrCreate(['wa_id' => $waId], $data)
                        : $conv->messages()->create($data);
                    $conv->update([
                        'preview' => mb_substr($text, 0, 80),
                        'time' => date('H:i', $ts),
                        'last_message_at' => now(),
                    ]);
                    // Depois do update: o evento lê a linha do banco (preview/hora atualizados).
                    Realtime::messageCreated($msg);
                }

                $rodada = $segundo ? '2º (começando)' : '1º';
                $this->info("lembrete {$rodada}: enviado p/ reunião {$meeting->id} ({$meeting->phone})");
            } finally {
                $tenancy->forget();
            }
        }
    }

    /**
     * Pedaços do lembrete (servidor — garante que o horário bate com o agendado).
     * São exatamente as 3 variáveis do template da Meta, para o cliente ler a mesma
     * coisa venha o aviso por texto livre ou por template.
     */
    private function messageParts(Meeting $meeting, bool $segundo = false): array
    {
        $tz = config('app.timezone', 'America/Sao_Paulo');
        $start = Carbon::parse($meeting->starts_at)->setTimezone($tz);

        $nome = trim((string) ($meeting->conversation->name ?? ''));
        $firstName = $nome !== '' ? (preg_split('/\s+/', $nome)[0] ?? '') : '';
        $hasName = $firstName !== '' && ! preg_match('/^\+?\d+$/', $firstName);

        // "hoje às 14:00" / "amanhã às 14:00" / "na quinta, 20/06 às 14:00"
        $hora = $start->format('H:i');
        $hoje = Carbon::now($tz)->startOfDay();
        $dia = $start->copy()->startOfDay();
        if ($dia->equalTo($hoje)) {
            $quando = "hoje às {$hora}";
        } elseif ($dia->equalTo($hoje->copy()->addDay())) {
            $quando = "amanhã às {$hora}";
        } else {
            $quando = 'na '.$start->locale('pt_BR')->isoFormat('dddd, DD/MM')." às {$hora}";
        }

        // No 2º aviso o valor é a contagem, não a data: "em 12 minutos, às 14:00". Uso os
        // minutos reais (o tick roda a cada minuto e pode pegar a reunião com 13 ou 14 de
        // folga) — dizer "15" quando faltam 8 faria o cliente se atrasar de propósito.
        if ($segundo) {
            $faltam = max(1, (int) ceil(Carbon::now($tz)->diffInSeconds($start, false) / 60));
            $quando = "em {$faltam} ".($faltam === 1 ? 'minuto' : 'minutos').", às {$hora}";
        }

        return [
            // Variável de template não pode ir vazia: sem nome, vira uma saudação neutra.
            'nome' => $hasName ? $firstName : 'tudo bem',
            'quando' => $quando,
            'link' => $meeting->meet_link
                ? "Link do Google Meet: {$meeting->meet_link}"
                : 'Qualquer coisa, é só me chamar por aqui.',
        ];
    }

    /** Texto livre (dentro da janela de 24h) — mesmo conteúdo do template. */
    private function buildMessage(array $parts, bool $segundo = false): string
    {
        if ($segundo) {
            return "Oi, {$parts['nome']}! ⏰ Nossa reunião começa {$parts['quando']}."
                ."\n\n{$parts['link']}"
                ."\n\nJá estou entrando — te espero lá! 😊";
        }

        return "Oi, {$parts['nome']}! 👋 Passando pra lembrar da nossa reunião {$parts['quando']}."
            ."\n\n{$parts['link']}"
            ."\n\nAté logo! 😊";
    }
}
