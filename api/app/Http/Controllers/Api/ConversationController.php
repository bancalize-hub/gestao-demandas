<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Stage;
use App\Services\AiReplyService;
use App\Services\MeetingScheduler;
use App\Support\Evolution;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(private AiReplyService $ai, private MeetingScheduler $scheduler) {}

    public function index()
    {
        // A LISTA carrega só a última mensagem de cada conversa (preview + ✓✓), não as 11k+
        // mensagens de todas — a thread vem do endpoint /full ao abrir a conversa.
        // started_ts = ts da 1ª mensagem (data do 1º contato) — usado no filtro de data do Funil.
        // Colunas enxutas (LIST_COLUMNS): com centenas de conversas re-baixadas em tempo
        // real, cada coluna extra aqui vira dezenas de KB por refresh em cada aba aberta.
        return Conversation::listQuery()
            ->orderByRaw('last_message_at IS NULL, last_message_at DESC')
            ->orderBy('position')
            ->get();
    }

    /** Linha única da lista (mesmo formato do index) — patch incremental via tempo real. */
    public function show(Conversation $conversation)
    {
        return Conversation::listQuery()->whereKey($conversation->id)->firstOrFail();
    }

    /**
     * Indicadores do dia. "Leads novos": conversas que receberam mensagem do lead HOJE e que
     * NÃO tinham nenhuma mensagem antes de hoje (contato que chegou hoje). Fuso de São Paulo.
     */
    public function todayStats()
    {
        $tz = config('app.timezone', 'America/Sao_Paulo');
        $start = \Carbon\Carbon::today($tz)->timestamp;
        $end = \Carbon\Carbon::tomorrow($tz)->timestamp;

        $convs = Conversation::query()
            ->where('archived', false)
            ->whereHas('messages', fn ($q) => $q->where('is_out', false)->whereBetween('ts', [$start, $end]))
            ->whereDoesntHave('messages', fn ($q) => $q->where('ts', '<', $start)->orWhereNull('ts'))
            ->orderByDesc('last_message_at')
            ->get(['slug', 'name', 'time']);

        return response()->json([
            'new_leads' => $convs->count(),
            'leads' => $convs->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name, 'time' => $c->time])->values(),
        ]);
    }

    public function update(Request $request, Conversation $conversation)
    {
        $oldStage = $conversation->stage;

        $conversation->update($request->only([
            'unread', 'preview', 'time', 'stage', 'stage_color', 'prob', 'hot', 'online', 'archived', 'tags',
            'deal_value', 'deal_unit', 'name', 'auto_reply',
            // Ficha do lead (CRM) — edição manual pela ScreenContact.
            'email', 'company', 'origin', 'responsible', 'role', 'segmento', 'notes', 'custom_fields',
        ]));

        // Ligou o atendimento automático: se o lead está aguardando (última mensagem é dele),
        // já agenda uma resposta. Desligou: cancela qualquer resposta pendente.
        if ($conversation->wasChanged('auto_reply')) {
            if ($conversation->auto_reply) {
                $last = $conversation->messages()->reorder()->orderByDesc('ts')->orderByDesc('id')->first();
                $conversation->auto_reply_due_at = ($last && ! $last->is_out) ? now() : null;
            } else {
                $conversation->auto_reply_due_at = null;
            }
            $conversation->save();
        }

        // Nome salvo manualmente → recalcula as iniciais do avatar.
        if ($request->filled('name')) {
            $parts = preg_split('/\s+/', trim((string) $request->input('name')), -1, PREG_SPLIT_NO_EMPTY);
            $ini = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
            $conversation->update(['initials' => $ini ?: '#']);
        }

        // Mudou a etapa → registra na linha do tempo e sincroniza a etiqueta no WhatsApp.
        if ($conversation->wasChanged('stage')) {
            $stageName = \App\Models\Stage::where('key', $conversation->stage)->value('name') ?? $conversation->stage;
            \App\Models\LeadActivity::log($conversation->id, 'etapa', "Movido para “{$stageName}”", null, $request->user()?->id);
            \App\Services\StageMover::syncWhatsAppLabel($conversation, $oldStage, $conversation->stage);
        }

        return $conversation->load('messages');
    }

    /** Sugestão de próxima resposta — no seu estilo e usando a memória (Claude/assinatura). */
    public function suggestReply(Request $request, Conversation $conversation)
    {
        $reply = $this->ai->generate(
            $conversation,
            $request->input('instruction'),
            $request->input('previous'),
        );
        if ($reply === null) {
            return response()->json(['message' => 'Falha ao gerar sugestão'], 502);
        }

        return response()->json(['suggestion' => $reply]);
    }

    /**
     * Agenda uma reunião com o lead: a IA lê a conversa, escolhe o melhor
     * horário livre da agenda do atendente e marca (com Meet + e-mail do lead).
     * Se a janela estiver lotada, devolve só a sugestão de mensagem para o cliente.
     */
    public function scheduleMeeting(Request $request, Conversation $conversation)
    {
        $user = $request->user();
        if (! $user->hasGoogle()) {
            return response()->json([
                'error' => 'sem_google',
                'message' => 'Conecte sua conta Google na página Agenda antes de agendar reuniões.',
            ], 409);
        }
        if (! config('services.claude.oauth_token')) {
            return response()->json(['message' => 'IA indisponível no momento.'], 502);
        }

        $result = $this->scheduler->decideAndBook($user, $conversation, $this->ai->memoryContext($conversation));

        if (($result['error'] ?? null) === 'parse') {
            return response()->json(['message' => 'Não consegui interpretar a sugestão da IA. Tente de novo.'], 502);
        }

        return response()->json(array_filter([
            'scheduled' => $result['scheduled'],
            'message' => $result['message'] ?? null,
            'note' => $result['note'] ?? null,
            'event' => $result['event'] ?? null,
            'meet_link' => $result['meet_link'] ?? null,
            'slot_label' => $result['slot_label'] ?? null,
        ], fn ($v) => $v !== null));
    }

}
