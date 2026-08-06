<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\LeadActivity;
use App\Models\Meeting;
use App\Models\User;
use App\Support\Claude;
use App\Support\MetaConversions;
use Carbon\Carbon;

/**
 * Cérebro do agendamento de reuniões: a IA lê a conversa, e SÓ marca quando o lead
 * confirmou um dia/horário concretos. A data é ancorada nos dias livres que o servidor
 * ofereceu (a IA copia, não calcula) e o horário é conferido na agenda real (isFree).
 * Compartilhado entre o botão "Agendar" e o atendimento automático.
 *
 * REGRA DE OURO: cada contato tem NO MÁXIMO UM agendamento ativo. Antes de marcar qualquer
 * coisa consultamos o agendamento ativo da conversa; se já existir e o cliente citar uma data
 * nova, é REMARCAÇÃO (cancela o antigo ANTES de criar o novo), nunca uma segunda reunião.
 */
class MeetingScheduler
{
    public function __construct(private GoogleCalendarService $google) {}

    /**
     * Decide e executa a ação de agenda. Retorna um array neutro:
     * ['action'=>'marcar|remarcar|cancelar|perguntar|nada', 'scheduled'=>bool, 'rescheduled'=>bool,
     *  'cancelled'=>bool, 'message'=>?string, 'note'=>?string, 'event'=>?array, 'meet_link'=>?string,
     *  'slot_label'=>?string, 'previous_slot_label'=>?string, 'error'=>?string].
     */
    public function decideAndBook(User $user, Conversation $conversation, string $memoryContext = ''): array
    {
        $durationMin = 60; // reuniões de 1 hora
        $slots = $this->google->freeSlots($user, $durationMin);

        $transcript = $conversation->messages()
            ->where(function ($q) {
                $q->where(fn ($t) => $t->where('type', 'text')->whereNotNull('text'))
                    ->orWhere(fn ($v) => $v->where('type', 'voice')->whereNotNull('transcript')->where('transcript', '!=', ''));
            })
            ->reorder()->orderByRaw('ts IS NULL, ts')->orderBy('id')
            ->get(['is_out', 'type', 'text', 'transcript'])
            ->map(function ($m) use ($conversation) {
                $who = $m->is_out ? 'Atendente' : $conversation->name;
                $content = $m->type === 'voice' ? '[áudio do cliente] '.$m->transcript : $m->text;

                return $who.': '.$content;
            })
            ->implode("\n");
        if ($transcript === '') {
            $transcript = '(sem mensagens ainda — o lead acabou de iniciar a conversa)';
        }

        $lead = "Nome: {$conversation->name}"
            .($conversation->email ? " | E-mail: {$conversation->email}" : '')
            .($conversation->phone ? " | Telefone: {$conversation->phone}" : '')
            .($conversation->company ? " | Empresa: {$conversation->company}" : '');

        $tz = config('app.timezone', 'America/Sao_Paulo');

        $slotsList = '';
        foreach ($slots as $s) {
            $slotsList .= "- {$s['label']}\n";
        }
        if ($slotsList === '') {
            $slotsList = '(nenhum horário livre nos próximos dias)';
        }

        // Dias que a IA pode confirmar: QUALQUER dia útil nas próximas 3 semanas. A disponibilidade
        // real do horário é conferida depois por isFree() — por isso não limitamos aos poucos
        // horários sugeridos (era isso que fazia o agendamento falhar em silêncio quando o lead
        // confirmava um dia fora da pequena janela de sugestões).
        $allowedDays = [];
        $daysList = '';
        $cursor = Carbon::now($tz)->startOfDay();
        for ($d = 0; $d <= 21; $d++) {
            $day = $cursor->copy()->addDays($d);
            if ($day->isWeekend()) {
                continue;
            }
            $iso = $day->format('Y-m-d');
            $allowedDays[] = $iso;
            $daysList .= "- {$iso}  ({$day->locale('pt_BR')->isoFormat('dddd, DD/MM')})\n";
        }

        $agora = Carbon::now($tz);
        $agoraStr = $agora->locale('pt_BR')->isoFormat('dddd, DD/MM/YYYY HH:mm');

        // REGRA DE OURO — consulta obrigatória: este contato já tem agendamento ativo?
        $active = Meeting::activeFor($conversation->id);
        $rulesBlock = $active
            ? $this->activeMeetingRules($active, $tz)
            : $this->noMeetingRules();

        $prompt = <<<TXT
        Você é o assistente de um atendente que organiza reuniões com leads.

        DATA/HORA ATUAL: {$agoraStr} (fuso America/Sao_Paulo, offset -03:00).

        {$memoryContext}
        DADOS DO LEAD:
        {$lead}

        CONVERSA (Atendente = nós; {$conversation->name} = lead):
        {$transcript}

        SUGESTÕES DE HORÁRIO LIVRE (use ao PROPOR horários ao lead):
        {$slotsList}

        DIAS DISPONÍVEIS NA AGENDA (use o YYYY-MM-DD ao confirmar):
        {$daysList}
        {$rulesBlock}

        AO MARCAR OU REMARCAR ("action": "marcar" ou "remarcar"), preencha:
        - "book_day": a DATA combinada no formato YYYY-MM-DD. NUNCA calcule a data você mesmo — interprete
          "amanhã"/"quinta"/"depois do meio-dia" e COPIE o YYYY-MM-DD do dia certo da lista DIAS DISPONÍVEIS.
          Use exatamente o dia que foi combinado/oferecido na conversa.
        - "book_time": o horário que o lead confirmou em HH:MM 24h (ex.: "17:30"). PODE ser um horário que não
          aparece nas sugestões — o sistema confere a disponibilidade real na agenda antes de marcar.

        Sempre defina um "title" curto (ex.: "Reunião — Empresa · assunto"). Quando NÃO marcar/remarcar,
        escreva uma "message" curta em pt-BR seguindo EXATAMENTE o estilo/voz, as regras e o conhecimento
        descritos no topo (as confirmações de marcação, remarcação e cancelamento são geradas pelo sistema).

        Responda SOMENTE com JSON, sem texto fora dele:
        {"action": "marcar|remarcar|cancelar|perguntar|nada", "title": "...", "book_day": "YYYY-MM-DD ou vazio", "book_time": "HH:MM ou vazio", "message": "..."}
        TXT;

        $data = Claude::json(Claude::run($prompt, 90));
        if (! is_array($data) || ! isset($data['message'])) {
            return ['action' => 'nada', 'scheduled' => false, 'message' => null, 'error' => 'parse'];
        }

        $message = (string) $data['message'];
        $action = $this->resolveAction($data, $active);

        // Só perguntar (caso ambíguo: trocar a reunião ou marcar uma segunda?) — nada de agenda.
        if ($action === 'perguntar') {
            return ['action' => 'perguntar', 'scheduled' => false, 'message' => $message];
        }

        if ($action === 'cancelar') {
            return $this->cancel($user, $conversation, $active, $tz);
        }

        if ($action !== 'marcar' && $action !== 'remarcar') {
            return ['action' => 'nada', 'scheduled' => false, 'message' => $message];
        }

        $start = $this->resolveStart($data, $allowedDays, $tz);

        // Lead "confirmou", mas não obtivemos um dia/horário válido → não arrisca.
        if (! $start) {
            return [
                'action' => 'nada',
                'scheduled' => false,
                'message' => $message,
                'note' => 'Não consegui confirmar o dia/horário com segurança — confira com o lead antes de marcar.',
            ];
        }

        $end = $start->copy()->addMinutes($durationMin);

        // Remarcação para o MESMO horário que já está marcado: nada muda.
        if ($action === 'remarcar' && $active && $active->starts_at->equalTo($start)) {
            return [
                'action' => 'nada',
                'scheduled' => false,
                'message' => $message,
                'note' => 'O horário pedido é o mesmo que já está marcado.',
            ];
        }

        // Ao remarcar, a própria reunião não pode contar como conflito consigo mesma.
        $ignore = $action === 'remarcar' ? $active?->google_event_id : null;
        if (! $this->google->isFree($user, $start, $end, $ignore)) {
            return [
                'action' => 'nada',
                'scheduled' => false,
                'message' => $message,
                'note' => 'O horário combinado já está ocupado na sua agenda — proponha outro ao lead.',
            ];
        }

        return $action === 'remarcar' && $active
            ? $this->reschedule($user, $conversation, $active, $start, $end, (string) ($data['title'] ?? ''), $transcript)
            : $this->book($user, $conversation, $start, $end, (string) ($data['title'] ?? ''), $transcript);
    }

    /** Bloco de regras quando o contato NÃO tem agendamento ativo. */
    private function noMeetingRules(): string
    {
        return <<<'TXT'

        AGENDAMENTO ATIVO DESTE CONTATO: nenhum (consultado agora na agenda do CRM).

        REGRA PRINCIPAL — só marque a reunião se o LEAD JÁ CONFIRMOU um dia e horário concretos
        (ex.: "pode ser quinta às 14h", "amanhã 17:30 fica bom"):
        - "action": "marcar" nesse caso.
        - "action": "nada" em TODOS os outros casos (o lead ainda não confirmou). NÃO marque: deixe book_day
          e book_time vazios e escreva uma mensagem propondo 2 ou 3 das SUGESTÕES acima para ele confirmar.
        TXT;
    }

    /** Bloco de regras quando JÁ existe agendamento ativo (remarcação × segunda reunião). */
    private function activeMeetingRules(Meeting $active, string $tz): string
    {
        $when = $active->starts_at->copy()->setTimezone($tz);
        $label = $when->locale('pt_BR')->isoFormat('dddd, DD/MM [às] HH:mm');
        $iso = $when->format('Y-m-d H:i');
        $title = $active->title ?: 'Reunião';

        return <<<TXT

        AGENDAMENTO ATIVO DESTE CONTATO (consultado agora na agenda do CRM):
        - "{$title}" — {$label}  ({$iso})

        REGRA DE OURO: cada contato pode ter NO MÁXIMO UM agendamento ativo. Como JÁ EXISTE um, você
        NUNCA pode criar um segundo. Escolha a "action":
        - "remarcar": o cliente indicou um dia/horário DIFERENTE do que já está marcado. O sistema cancela
          o antigo ANTES de criar o novo. São pedidos de remarcação frases como "não vou conseguir nesse
          dia", "pode ser outro horário?", "surgiu um imprevisto", "melhor na quinta", "vamos passar para
          semana que vem", "preciso adiar". SINAL DECISIVO: o cliente menciona uma data ou horário
          diferente do que já existe — é remarcação mesmo que ele não use a palavra "remarcar".
        - "cancelar": o cliente só quer desmarcar, sem propor data nova. Na "message", pergunte se ele
          quer sugerir outro dia.
        - "perguntar": não está claro se ele quer TROCAR a reunião existente ou marcar uma SEGUNDA reunião
          adicional. Na "message" pergunte, por exemplo: "Só para confirmar: você quer transferir a reunião
          do dia {$label} para o novo horário, ou manter as duas?"
        - "nada": o cliente não falou em mudar nada (dúvida comum, confirmação, assunto qualquer).

        NA DÚVIDA entre remarcar e marcar uma reunião adicional, assuma sempre REMARCAÇÃO. Nunca trate
        "quinta" ou "amanhã" como reunião adicional quando já existe uma marcada.
        TXT;
    }

    /**
     * Normaliza a ação da IA e aplica a regra de ouro no servidor — a IA sugere, o servidor decide:
     * com agendamento ativo, "marcar" vira "remarcar"; sem agendamento ativo, "remarcar" vira "marcar"
     * e "cancelar" não tem o que cancelar.
     */
    private function resolveAction(array $data, ?Meeting $active): string
    {
        $action = strtolower(trim((string) ($data['action'] ?? '')));
        $action = match (true) {
            str_starts_with($action, 'remarc') => 'remarcar',
            str_starts_with($action, 'cancel') => 'cancelar',
            str_starts_with($action, 'pergunt') => 'perguntar',
            str_starts_with($action, 'marc'), $action === 'book', $action === 'agendar' => 'marcar',
            $action === 'nada', $action === 'none' => 'nada',
            // Sem "action" reconhecida, cai no campo antigo "book" (compatibilidade).
            default => filter_var($data['book'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'marcar' : 'nada',
        };

        if ($active) {
            // Nunca dois agendamentos ativos: pedido de marcar vira remarcação.
            return $action === 'marcar' ? 'remarcar' : $action;
        }

        return match ($action) {
            'remarcar' => 'marcar',   // não há o que remarcar → é a primeira reunião
            'cancelar' => 'nada',     // não há o que cancelar
            default => $action,
        };
    }

    /** Converte book_day/book_time da IA num Carbon válido (ou null se não der para confiar). */
    private function resolveStart(array $data, array $allowedDays, string $tz): ?Carbon
    {
        $bookDay = trim((string) ($data['book_day'] ?? ''));
        $bookTime = trim((string) ($data['book_time'] ?? ''));

        // A DATA é ancorada num dia útil real da janela (a IA copia da lista DIAS DISPONÍVEIS,
        // não calcula). Já o HORÁRIO pode ser qualquer um pedido pelo lead — conferido na agenda
        // via isFree() pelo chamador.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookDay)
            || ! in_array($bookDay, $allowedDays, true)
            || ! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $bookTime)) {
            return null;
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d H:i', $bookDay.' '.$bookTime, $tz);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $start || (int) $start->format('G') < 7 || (int) $start->format('G') > 21) {
            return null;
        }

        return $start;
    }

    /** Cria a reunião (evento + Meet + registro + etapa do funil). */
    private function book(User $user, Conversation $conversation, Carbon $start, Carbon $end, string $title, string $transcript): array
    {
        $event = $this->google->createEvent($user, [
            'title' => $title ?: ('Reunião — '.$conversation->name),
            'description' => "Reunião agendada a partir da conversa com {$conversation->name} no CRM.",
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'attendees' => $this->attendees($conversation, $transcript),
            'add_meet' => true,
        ]);

        $meet = $event['hangout_link'] ?? null;
        $slotLabel = $this->label($start);

        // Mensagem de confirmação montada pelo SERVIDOR — garante que o texto bate com a data marcada.
        $message = "{$this->saudacao($conversation)} Reunião confirmada para {$slotLabel}. Até lá! 😊";
        if ($meet) {
            $message .= "\n\nSegue o link da nossa reunião no Google Meet: {$meet}";
        }

        // Registra a reunião: serve para o lembrete (WhatsApp, se houver telefone) e para a
        // apuração de presença/resumo depois da reunião (Meet API + read.ai). user_id é a conta
        // Google que vai consultar a Meet API.
        Meeting::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'phone' => $conversation->phone,
            'title' => $event['title'] ?? ($title ?: null),
            'starts_at' => $start,
            'ends_at' => $end,
            'meet_link' => $meet,
            'google_event_id' => $event['id'] ?? null,
            'reminder_lead_minutes' => (int) config('services.meeting_reminder.lead_minutes', 60),
        ]);

        // Move o lead para "Reunião Agendada" no funil (etiqueta sincroniza no WhatsApp).
        StageMover::move(
            $conversation,
            (string) config('services.crm.stage_meeting_booked', 'proposta'),
            $user->id,
            "Reunião agendada para {$slotLabel}",
        );

        // Conta para o Facebook que este clique virou reunião — é o sinal que faz a
        // campanha de conversão otimizar por agendamento, não por clique.
        MetaConversions::enviarUmaVez($conversation, MetaConversions::REUNIAO_MARCADA);

        return [
            'action' => 'marcar',
            'scheduled' => true,
            'event' => $event,
            'meet_link' => $meet,
            'slot_label' => $slotLabel,
            'message' => $message,
        ];
    }

    /**
     * Remarca: move o evento existente em vez de criar um segundo. Se não der para atualizar
     * o evento no Google, cancela o antigo ANTES de criar o novo — nunca o contrário.
     */
    private function reschedule(User $user, Conversation $conversation, Meeting $active, Carbon $start, Carbon $end, string $title, string $transcript): array
    {
        $previousLabel = $this->label($active->starts_at);
        $attendees = $this->attendees($conversation, $transcript);
        $payload = [
            'title' => $title ?: ($active->title ?: 'Reunião — '.$conversation->name),
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'attendees' => $attendees,
            'add_meet' => true,
        ];

        $event = null;
        if ($active->google_event_id) {
            try {
                $event = $this->google->updateEvent($user, $active->google_event_id, $payload);
            } catch (\Throwable $e) {
                // Evento sumiu/não pôde ser movido: apaga o antigo ANTES de criar o novo.
                $this->google->deleteEvent($user, $active->google_event_id);
                $event = null;
            }
        }
        if (! $event) {
            $event = $this->google->createEvent($user, $payload + [
                'description' => "Reunião remarcada a partir da conversa com {$conversation->name} no CRM.",
            ]);
        }

        $meet = $event['hangout_link'] ?? $active->meet_link;
        $slotLabel = $this->label($start);

        $active->update([
            'title' => $event['title'] ?? $active->title,
            'starts_at' => $start,
            'ends_at' => $end,
            'meet_link' => $meet,
            'google_event_id' => $event['id'] ?? $active->google_event_id,
            'phone' => $active->phone ?: $conversation->phone,
            'user_id' => $active->user_id ?: $user->id,
            // Lembrete e apuração valem para o horário NOVO.
            'reminder_sent_at' => null,
            'attendance_checked_at' => null,
        ]);

        LeadActivity::log(
            $conversation->id,
            'reuniao',
            "Reunião remarcada: {$previousLabel} → {$slotLabel}",
            null,
            $user->id,
        );

        // Reunião marcada continua valendo como conversão para a Meta (enviarUmaVez não repete):
        // cobre o lead cuja 1ª marcação é anterior à CAPI e que só agora remarcou.
        MetaConversions::enviarUmaVez($conversation, MetaConversions::REUNIAO_MARCADA);

        // Confirma explicitamente o cancelamento do horário antigo.
        $message = "Pronto! Cancelei {$previousLabel} e sua reunião ficou para {$slotLabel}.";
        if ($meet) {
            $message .= "\n\nO link do Google Meet continua o mesmo: {$meet}";
        }

        return [
            'action' => 'remarcar',
            'scheduled' => true,
            'rescheduled' => true,
            'event' => $event,
            'meet_link' => $meet,
            'slot_label' => $slotLabel,
            'previous_slot_label' => $previousLabel,
            'message' => $message,
        ];
    }

    /** Cancela o agendamento ativo (apaga o evento) e convida o lead a sugerir outro dia. */
    private function cancel(User $user, Conversation $conversation, ?Meeting $active, string $tz): array
    {
        if (! $active) {
            return ['action' => 'nada', 'scheduled' => false, 'message' => null];
        }

        $label = $this->label($active->starts_at);

        if ($active->google_event_id) {
            $this->google->deleteEvent($user, $active->google_event_id);
        }

        $active->update([
            'cancelled_at' => now(),
            'cancel_reason' => 'pedido do cliente',
            'reminder_sent_at' => now(), // não lembra de reunião cancelada
        ]);

        LeadActivity::log($conversation->id, 'reuniao', "Reunião de {$label} cancelada pelo cliente", null, $user->id);

        return [
            'action' => 'cancelar',
            'scheduled' => false,
            'cancelled' => true,
            'previous_slot_label' => $label,
            'message' => "Sem problema! Cancelei a reunião de {$label}. Quer sugerir outro dia e horário para remarcarmos?",
        ];
    }

    /** Convida o lead: usa o e-mail do cadastro ou, na falta, o que ele digitou na conversa. */
    private function attendees(Conversation $conversation, string $transcript): array
    {
        $leadEmail = trim((string) $conversation->email);
        if (! filter_var($leadEmail, FILTER_VALIDATE_EMAIL)
            && preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $transcript, $mm)) {
            $leadEmail = $mm[0];
        }

        return filter_var($leadEmail, FILTER_VALIDATE_EMAIL) ? [$leadEmail] : [];
    }

    private function label(Carbon $when): string
    {
        return $when->copy()
            ->setTimezone(config('app.timezone', 'America/Sao_Paulo'))
            ->locale('pt_BR')->isoFormat('dddd, DD/MM [às] HH:mm');
    }

    private function saudacao(Conversation $conversation): string
    {
        $firstName = preg_split('/\s+/', trim((string) $conversation->name))[0] ?? '';
        $hasName = $firstName !== '' && ! preg_match('/^\+?\d+$/', $firstName);

        return $hasName ? "Perfeito, {$firstName}!" : 'Perfeito!';
    }
}
