<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use App\Support\Claude;
use Carbon\Carbon;

/**
 * Cérebro do agendamento de reuniões: a IA lê a conversa, e SÓ marca quando o lead
 * confirmou um dia/horário concretos. A data é ancorada nos dias livres que o servidor
 * ofereceu (a IA copia, não calcula) e o horário é conferido na agenda real (isFree).
 * Compartilhado entre o botão "Agendar" e o atendimento automático.
 */
class MeetingScheduler
{
    public function __construct(private GoogleCalendarService $google) {}

    /**
     * Decide e, se o lead confirmou, agenda a reunião. Retorna um array neutro:
     * ['scheduled'=>bool, 'message'=>?string, 'note'=>?string, 'event'=>?array,
     *  'meet_link'=>?string, 'slot_label'=>?string, 'error'=>?string].
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

        REGRA PRINCIPAL — só marque a reunião se o LEAD JÁ CONFIRMOU um dia e horário concretos
        (ex.: "pode ser quinta às 14h", "amanhã 17:30 fica bom"):
        - "book": true nesse caso.
        - "book": false em TODOS os outros casos (o lead ainda não confirmou). NÃO marque: deixe book_day e
          book_time vazios e escreva uma mensagem propondo 2 ou 3 das SUGESTÕES acima para ele confirmar.

        AO MARCAR ("book": true), preencha:
        - "book_day": a DATA combinada no formato YYYY-MM-DD. NUNCA calcule a data você mesmo — interprete
          "amanhã"/"quinta"/"depois do meio-dia" e COPIE o YYYY-MM-DD do dia certo da lista DIAS DISPONÍVEIS.
          Use exatamente o dia que foi combinado/oferecido na conversa.
        - "book_time": o horário que o lead confirmou em HH:MM 24h (ex.: "17:30"). PODE ser um horário que não
          aparece nas sugestões — o sistema confere a disponibilidade real na agenda antes de marcar.

        Sempre defina um "title" curto (ex.: "Reunião — Empresa · assunto"). Para "book": false escreva uma
        "message" curta em pt-BR seguindo EXATAMENTE o estilo/voz, as regras e o conhecimento descritos no topo
        (a confirmação final, quando marca, é gerada pelo sistema).

        Responda SOMENTE com JSON, sem texto fora dele:
        {"book": true|false, "title": "...", "book_day": "YYYY-MM-DD ou vazio", "book_time": "HH:MM ou vazio", "message": "..."}
        TXT;

        $data = Claude::json(Claude::run($prompt, 90));
        if (! is_array($data) || ! isset($data['message'])) {
            return ['scheduled' => false, 'message' => null, 'error' => 'parse'];
        }

        $book = filter_var($data['book'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $bookDay = trim((string) ($data['book_day'] ?? ''));
        $bookTime = trim((string) ($data['book_time'] ?? ''));

        // A DATA é ancorada num dia útil real da janela (a IA copia da lista DIAS DISPONÍVEIS,
        // não calcula). Já o HORÁRIO pode ser qualquer um pedido pelo lead — conferido na agenda
        // via isFree() logo abaixo.
        $start = null;
        if ($book
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookDay)
            && in_array($bookDay, $allowedDays, true)
            && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $bookTime)) {
            try {
                $start = Carbon::createFromFormat('Y-m-d H:i', $bookDay.' '.$bookTime, $tz);
            } catch (\Throwable $e) {
                $start = null;
            }
            if ($start && ((int) $start->format('G') < 7 || (int) $start->format('G') > 21)) {
                $start = null;
            }
        }

        // Lead "confirmou", mas não obtivemos um dia/horário válido → não arrisca.
        if ($book && ! $start) {
            return [
                'scheduled' => false,
                'message' => $data['message'],
                'note' => 'Não consegui confirmar o dia/horário com segurança — confira com o lead antes de marcar.',
            ];
        }

        if ($book && $start) {
            $end = $start->copy()->addMinutes($durationMin);

            if (! $this->google->isFree($user, $start, $end)) {
                return [
                    'scheduled' => false,
                    'message' => $data['message'],
                    'note' => 'O horário combinado já está ocupado na sua agenda — proponha outro ao lead.',
                ];
            }

            // Convida o lead: usa o e-mail do cadastro ou, na falta, o que ele digitou na conversa.
            $attendees = [];
            $leadEmail = trim((string) $conversation->email);
            if (! filter_var($leadEmail, FILTER_VALIDATE_EMAIL)
                && preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $transcript, $mm)) {
                $leadEmail = $mm[0];
            }
            if (filter_var($leadEmail, FILTER_VALIDATE_EMAIL)) {
                $attendees[] = $leadEmail;
            }

            $event = $this->google->createEvent($user, [
                'title' => $data['title'] ?? ('Reunião — '.$conversation->name),
                'description' => "Reunião agendada a partir da conversa com {$conversation->name} no CRM.",
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'attendees' => $attendees,
                'add_meet' => true,
            ]);

            // Mensagem de confirmação montada pelo SERVIDOR — garante que o texto bate com a data marcada.
            $firstName = preg_split('/\s+/', trim((string) $conversation->name))[0] ?? '';
            $hasName = $firstName !== '' && ! preg_match('/^\+?\d+$/', $firstName);
            $saudacao = $hasName ? "Perfeito, {$firstName}!" : 'Perfeito!';
            $slotLabel = $start->locale('pt_BR')->isoFormat('dddd, DD/MM [às] HH:mm');
            $message = "{$saudacao} Reunião confirmada para {$slotLabel}. Até lá! 😊";
            $meet = $event['hangout_link'] ?? null;
            if ($meet) {
                $message .= "\n\nSegue o link da nossa reunião no Google Meet: {$meet}";
            }

            // Registra a reunião: serve para o lembrete (WhatsApp, se houver telefone) e para a
            // apuração de presença/resumo depois da reunião (Meet API + read.ai). user_id é a conta
            // Google que vai consultar a Meet API.
            \App\Models\Meeting::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'phone' => $conversation->phone,
                'title' => $event['title'] ?? ($data['title'] ?? null),
                'starts_at' => $start,
                'ends_at' => $end,
                'meet_link' => $meet,
                'google_event_id' => $event['id'] ?? null,
                'reminder_lead_minutes' => (int) config('services.meeting_reminder.lead_minutes', 60),
            ]);

            // Move o lead para "Reunião Agendada" no funil (etiqueta sincroniza no WhatsApp).
            \App\Services\StageMover::move(
                $conversation,
                (string) config('services.crm.stage_meeting_booked', 'proposta'),
                $user->id,
                "Reunião agendada para {$slotLabel}",
            );

            return [
                'scheduled' => true,
                'event' => $event,
                'meet_link' => $meet,
                'slot_label' => $slotLabel,
                'message' => $message,
            ];
        }

        // Lead ainda não confirmou: devolve só a sugestão de mensagem.
        return ['scheduled' => false, 'message' => $data['message']];
    }
}
