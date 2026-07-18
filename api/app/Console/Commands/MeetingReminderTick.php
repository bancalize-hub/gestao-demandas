<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Support\Evolution;
use App\Support\Tenancy;
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
            ->whereNull('reminder_sent_at')
            ->whereNotNull('phone')
            ->where('starts_at', '>', now())
            ->whereRaw('starts_at <= DATE_ADD(?, INTERVAL reminder_lead_minutes MINUTE)', [now()])
            ->with('conversation')
            ->get();

        $tenancy = app(Tenancy::class);

        foreach ($due as $meeting) {
            // Contexto da empresa dona da reunião: o envio usa o WhatsApp dela e a
            // mensagem espelhada nasce carimbada.
            $tenancy->set((int) $meeting->company_id);

            try {
            $text = $this->buildMessage($meeting);

            $waId = Evolution::sendText($meeting->phone, $text);
            if ($waId === null) {
                $this->warn("lembrete: falha ao enviar reunião {$meeting->id} ({$meeting->phone})");

                continue; // tenta de novo no próximo tick (reminder_sent_at continua null)
            }

            $meeting->update(['reminder_sent_at' => now()]);

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
                \App\Support\Realtime::messageCreated($msg);
            }

            $this->info("lembrete: enviado p/ reunião {$meeting->id} ({$meeting->phone})");
            } finally {
                $tenancy->forget();
            }
        }

        return self::SUCCESS;
    }

    /** Monta o texto do lembrete (servidor — garante que o horário bate com o agendado). */
    private function buildMessage(Meeting $meeting): string
    {
        $tz = config('app.timezone', 'America/Sao_Paulo');
        $start = Carbon::parse($meeting->starts_at)->setTimezone($tz);

        $nome = trim((string) ($meeting->conversation->name ?? ''));
        $firstName = $nome !== '' ? (preg_split('/\s+/', $nome)[0] ?? '') : '';
        $hasName = $firstName !== '' && ! preg_match('/^\+?\d+$/', $firstName);
        $saudacao = $hasName ? "Oi, {$firstName}! 👋" : 'Oi! 👋';

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

        $msg = "{$saudacao} Passando pra lembrar da nossa reunião {$quando}. Até logo! 😊";
        if ($meeting->meet_link) {
            $msg .= "\n\nLink do Google Meet: {$meeting->meet_link}";
        }

        return $msg;
    }
}
