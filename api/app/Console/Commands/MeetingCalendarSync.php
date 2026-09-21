<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\Message;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Importa para a tabela `meetings` as reuniões com Google Meet criadas DIRETO no
 * Google Agenda (não pelo CRM). Assim o pós-reunião (meetings:attendance-tick) também
 * processa elas e o resumo do read.ai cai na ficha do lead. Idempotente por google_event_id.
 *
 * Janela: 14 dias atrás (cobre o histórico recente) + 30 dias à frente.
 */
class MeetingCalendarSync extends Command
{
    protected $signature = 'meetings:calendar-sync';

    protected $description = 'Importa reuniões com Meet do Google Agenda para o CRM (casando com o lead)';

    public function handle(GoogleCalendarService $google): int
    {
        $tenancy = app(Tenancy::class);

        // TODAS as agendas conectadas de cada empresa — a agenda do CRM é a da empresa, e
        // uma reunião marcada na agenda do segundo usuário também precisa do pós-reunião
        // (presença/resumo). Idempotente por google_event_id, então ver o mesmo evento em
        // duas agendas não duplica nada. Cada empresa casa só com os SEUS leads.
        $users = User::whereNotNull('google_access_token')
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('Nenhuma conta Google conectada — nada a sincronizar.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            if (! $user->hasGoogle()) {
                continue;
            }
            $tenancy->run((int) $user->company_id, fn () => $this->syncUser($google, $user));
        }

        return self::SUCCESS;
    }

    /** Sincroniza a agenda de UMA empresa (contexto de tenancy já vinculado). */
    private function syncUser(GoogleCalendarService $google, User $user): void
    {
        $tz = config('app.timezone', 'America/Sao_Paulo');
        $from = Carbon::now($tz)->subDays(14);
        $to = Carbon::now($tz)->addDays(30);

        try {
            $events = $google->listEvents($user, $from, $to);
        } catch (\Throwable $e) {
            $this->warn("Empresa {$user->company_id}: falha ao listar a agenda: ".$e->getMessage());

            return;
        }

        $imported = 0;
        $matched = 0;
        foreach ($events as $e) {
            $meet = $e['hangout_link'] ?? null;
            $eventId = $e['id'] ?? null;
            // Só reuniões com Meet, com horário definido (ignora all-day) e id válido.
            if (! $meet || ! $eventId || empty($e['starts_at']) || ! empty($e['all_day'])) {
                continue;
            }

            // Já existe (criada pelo CRM ou importada antes)? Só completa o vínculo se faltar.
            $existing = Meeting::where('google_event_id', $eventId)->first();
            if ($existing) {
                if (! $existing->conversation_id) {
                    $conv = $this->matchConversation($e);
                    if ($conv) {
                        $existing->update(['conversation_id' => $conv->id, 'phone' => $existing->phone ?: $conv->phone]);
                        $matched++;
                    }
                }

                continue;
            }

            $start = Carbon::parse($e['starts_at'])->setTimezone($tz);
            $end = ! empty($e['ends_at']) ? Carbon::parse($e['ends_at'])->setTimezone($tz) : $start->copy()->addHour();
            $conv = $this->matchConversation($e);
            if ($conv) {
                $matched++;
            }

            Meeting::create([
                'conversation_id' => $conv?->id,
                'user_id' => $user->id,
                'phone' => $conv?->phone,
                'title' => $e['title'] ?? 'Reunião',
                'starts_at' => $start,
                'ends_at' => $end,
                'meet_link' => $meet,
                'google_event_id' => $eventId,
                'reminder_lead_minutes' => (int) config('services.meeting_reminder.lead_minutes', 60),
                // Passadas → marca como "enviado" (não faz sentido lembrar de reunião que já ocorreu,
                // evita flood ao importar histórico). Futuras → deixa o lembrete automático cuidar
                // (só dispara se a reunião casou com um lead que tem telefone).
                'reminder_sent_at' => $start->isPast() ? now() : null,
            ]);
            $imported++;
        }

        // Reunião apagada direto no Google Calendar continuaria "ativa" aqui e travaria novos
        // agendamentos do contato (regra de ouro: um ativo por vez). Some do calendário → cancela.
        $vivos = collect($events)->pluck('id')->filter()->all();
        $sumidas = 0;
        // Só reconcilia com a lista COMPLETA da janela: se a agenda bateu o teto do listEvents,
        // a lista pode estar truncada e cancelaríamos reunião que existe. E só mexe nas reuniões
        // desta conta Google (outro atendente tem a agenda dele).
        if (count($events) < 250) {
            $sumidas = Meeting::query()
                ->where('user_id', $user->id)
                ->whereNull('cancelled_at')
                ->whereNotNull('google_event_id')
                ->whereBetween('starts_at', [$from, $to])
                ->whereNotIn('google_event_id', $vivos ?: [''])
                ->update(['cancelled_at' => now(), 'cancel_reason' => 'apagada no Google Calendar']);
        }

        // Gravação automática nas reuniões FUTURAS desta conta que ainda não têm — cobre
        // as marcadas direto no Google Agenda, as recém-importadas e as agendadas antes do
        // recurso existir (ou antes de a conta reconectar o Google com o escopo novo).
        // Sucesso carimba `auto_record_enabled_at` e não repete; falha (sem escopo/Workspace,
        // convite de terceiro) tenta de novo a cada rodada até a reunião começar.
        $autoRec = 0;
        $pendentes = Meeting::query()
            ->where('user_id', $user->id)
            ->whereNull('auto_record_enabled_at')
            ->whereNull('cancelled_at')
            ->whereNotNull('meet_link')
            ->where('starts_at', '>', now())
            ->get();
        foreach ($pendentes as $p) {
            if ($google->enableAutoRecording($user, $p->meet_link)) {
                $p->update(['auto_record_enabled_at' => now()]);
                $autoRec++;
            }
        }

        $this->info("empresa {$user->company_id} calendar-sync: {$imported} reuniões importadas, {$matched} casadas com um lead, {$sumidas} canceladas (sumiram da agenda), {$autoRec} com gravação automática ligada.");
    }

    /**
     * Tenta achar o lead da reunião:
     *  1) pelo link do Meet citado nas mensagens da conversa (reunião marcada pelo CRM);
     *  2) pelo e-mail de um convidado == e-mail do lead.
     */
    private function matchConversation(array $event): ?Conversation
    {
        $code = $this->meetingCode($event['hangout_link'] ?? null);
        if ($code) {
            $msg = Message::where('text', 'like', '%'.$code.'%')->orderByDesc('id')->first();
            if ($msg) {
                $conv = Conversation::find($msg->conversation_id);
                if ($conv) {
                    return $conv;
                }
            }
        }

        foreach ($event['attendees'] ?? [] as $a) {
            $email = trim((string) ($a['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $conv = Conversation::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
            if ($conv) {
                return $conv;
            }
        }

        return null;
    }

    /** Código do link do Meet (ex.: .../abc-defg-hij → abc-defg-hij). */
    private function meetingCode(?string $link): ?string
    {
        if ($link && preg_match('#meet\.google\.com/([a-z]{3,4}-[a-z]{3,4}-[a-z]{3,4})#i', $link, $m)) {
            return $m[1];
        }

        return null;
    }
}
