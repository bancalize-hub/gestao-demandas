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
        // mensagens de todas — a thread completa vem do endpoint /full ao abrir a conversa.
        return Conversation::with('lastMessage')
            ->orderByRaw('last_message_at IS NULL, last_message_at DESC')
            ->orderBy('position')
            ->get();
    }

    public function update(Request $request, Conversation $conversation)
    {
        $oldStage = $conversation->stage;

        $conversation->update($request->only([
            'unread', 'preview', 'time', 'stage', 'stage_color', 'prob', 'hot', 'online', 'archived', 'tags',
            'deal_value', 'deal_unit', 'name', 'auto_reply',
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

        // Mudou a etapa → sincroniza a etiqueta no WhatsApp Business (sistema → WhatsApp).
        if ($conversation->wasChanged('stage') && $conversation->phone) {
            $this->syncStageLabel($conversation, $oldStage, $conversation->stage);
        }

        return $conversation->load('messages');
    }

    /** Aplica no WhatsApp a etiqueta da nova etapa e remove a da etapa anterior. */
    private function syncStageLabel(Conversation $conversation, ?string $oldKey, ?string $newKey): void
    {
        $number = preg_replace('/\D/', '', (string) $conversation->phone);
        if ($number === '') {
            return;
        }
        $old = $oldKey ? Stage::where('key', $oldKey)->first() : null;
        $new = $newKey ? Stage::where('key', $newKey)->first() : null;

        if ($old && $old->wa_label_id) {
            Evolution::handleLabel($number, $old->wa_label_id, 'remove');
        }
        if ($new && $new->wa_label_id) {
            Evolution::handleLabel($number, $new->wa_label_id, 'add');
        }
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
