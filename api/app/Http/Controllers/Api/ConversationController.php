<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\LeadActivity;
use App\Models\Stage;
use App\Services\AiReplyService;
use App\Services\MeetingScheduler;
use App\Services\StageMover;
use App\Support\Avatars;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ConversationController extends Controller
{
    public function __construct(private AiReplyService $ai, private MeetingScheduler $scheduler) {}

    public function index(Request $request)
    {
        // A LISTA carrega só a última mensagem de cada conversa (preview + ✓✓), não as 11k+
        // mensagens de todas — a thread vem do endpoint /full ao abrir a conversa.
        // started_ts = ts da 1ª mensagem (data do 1º contato) — usado no filtro de data do Funil.
        // Colunas enxutas (LIST_COLUMNS): com centenas de conversas re-baixadas em tempo
        // real, cada coluna extra aqui vira dezenas de KB por refresh em cada aba aberta.
        //
        // ?desqualificados=1 devolve SÓ os leads reprovados na triagem — a tab
        // "Desqualificados" os pede sob demanda para revisar/corrigir a marca. Traz todos,
        // inclusive os que a carga normal já mandou (lead com reunião ou fora da 1ª etapa
        // continua visível): a tab é "todos os reprovados", e o front deduplica.
        //
        // ?excluidas=1 é a lixeira: devolve SÓ as conversas "excluídas" (as que ganharam
        // hidden_at). Nada foi apagado — a tab de lixeira é o caminho de volta para elas,
        // que antes só existia nos 8 segundos do Desfazer.
        $soDesqualificados = $request->boolean('desqualificados');
        $soExcluidas = $request->boolean('excluidas');

        return Conversation::listQuery($soDesqualificados || $soExcluidas, $soExcluidas)
            ->when($soDesqualificados, fn ($q) => $q->where('conversations.qualified', false))
            ->orderByRaw('last_message_at IS NULL, last_message_at DESC')
            ->orderBy('position')
            ->get();
    }

    /**
     * Linha única da lista (mesmo formato do index) — patch incremental via tempo real.
     *
     * Traz o lead mesmo desqualificado ou excluído: quem esconde da tela é o front, pela
     * marca que vem nesta linha. Se aqui filtrasse, o patch viraria 404 e o front apagaria a
     * conversa da memória — e aí nem a requalificação (que chega por este mesmo caminho)
     * nem a tab de lixeira teriam como mostrá-la sem recarregar a página.
     */
    public function show(Conversation $conversation)
    {
        return Conversation::listQuery(true, null)->whereKey($conversation->id)->firstOrFail();
    }

    /**
     * Indicadores do dia. "Leads novos": conversas que receberam mensagem do lead HOJE e que
     * NÃO tinham nenhuma mensagem antes de hoje (contato que chegou hoje). Fuso de São Paulo.
     */
    public function todayStats()
    {
        $tz = config('app.timezone', 'America/Sao_Paulo');
        $start = Carbon::today($tz)->timestamp;
        $end = Carbon::tomorrow($tz)->timestamp;

        $convs = Conversation::query()
            ->where('archived', false)
            ->whereNull('hidden_at')
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
            'qualified',
            // Dono do negócio (users.id). O campo `responsible` acima é texto livre e está
            // poluído com nome de lead — ele continua existindo para não quebrar o que já
            // usa, mas quem manda no watchdog e nos relatórios é este.
            'owner_user_id',
        ]));

        // Triagem feita por gente vence a da IA e passa a ser definitiva: o tick só mexe
        // em quem ainda está NULL ou traz marca automática. Sem isto, o palpite da IA
        // voltaria por cima da correção de quem leu a conversa.
        if ($conversation->wasChanged('qualified')) {
            $conversation->qualified_auto = false;
            // Carimba a hora da decisão HUMANA também. Quem reprova à mão faz o lead perder
            // o horário (QualificarTick::desmarcarReprovados), e sem a hora não dá para
            // separar "acabou de decidir" de "está só ciclando o chip" — o ciclo passa por
            // desqualificado no caminho de volta para "sem triagem".
            $conversation->qualified_at = now();
            $conversation->save();
        }

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
            $stageName = Stage::where('key', $conversation->stage)->value('name') ?? $conversation->stage;
            LeadActivity::log($conversation->id, 'etapa', "Movido para “{$stageName}”", null, $request->user()?->id);
            StageMover::syncWhatsAppLabel($conversation, $oldStage, $conversation->stage);
        }

        return $conversation->load('messages');
    }

    /**
     * "Excluir" a conversa: some da tela, mas nada é apagado.
     *
     * O histórico continua no banco de propósito — é o que sustenta métrica de funil,
     * custo por lead e a auditoria de quem falou o quê. Apagar de verdade jogaria fora
     * dado que o /marketing conta, e não dá para desfazer.
     *
     * Ela reaparece sozinha se o contato mandar mensagem nova (ver a migration).
     */
    public function destroy(Conversation $conversation)
    {
        $conversation->update(['hidden_at' => now()]);

        return response()->json(['ok' => true, 'hidden_at' => $conversation->hidden_at]);
    }

    /** Desfaz o "excluir" — usado pelo botão Desfazer do aviso na tela. */
    public function restore(Conversation $conversation)
    {
        $conversation->update(['hidden_at' => null]);

        return Conversation::listQuery(true, null)->whereKey($conversation->id)->firstOrFail();
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

        // O marcador de material é para o envio automático; na sugestão manual o vendedor
        // anexa o que quiser — devolver "[MATERIAL: #3]" no campo de texto seria ruído.
        [$texto, $material] = AiReplyService::extrairMaterial($reply);

        return response()->json([
            'suggestion' => $texto,
            'material' => $material ? ['id' => $material->id, 'name' => $material->name] : null,
        ]);
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

        // O agendador consulta sozinho o agendamento ativo do contato (regra de ouro: um por vez)
        // e decide entre marcar, REMARCAR o que existe, cancelar ou perguntar.
        $result = $this->scheduler->decideAndBook($user, $conversation, $this->ai->memoryContext($conversation));

        if (($result['error'] ?? null) === 'parse') {
            return response()->json(['message' => 'Não consegui interpretar a sugestão da IA. Tente de novo.'], 502);
        }

        return response()->json(array_filter([
            'scheduled' => $result['scheduled'],
            'action' => $result['action'] ?? null,                 // marcar|remarcar|cancelar|perguntar|nada
            'rescheduled' => $result['rescheduled'] ?? null,
            'cancelled' => $result['cancelled'] ?? null,
            'message' => $result['message'] ?? null,
            'note' => $result['note'] ?? null,
            'event' => $result['event'] ?? null,
            'meet_link' => $result['meet_link'] ?? null,
            'slot_label' => $result['slot_label'] ?? null,
            'previous_slot_label' => $result['previous_slot_label'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Foto de perfil do contato, servida do NOSSO disco.
     *
     * A coluna `avatar` guarda a URL que o WhatsApp devolveu, e essa URL MORRE: o link do
     * `pps.whatsapp.net` carrega um `oe=` de validade e depois de alguns dias responde 403.
     * Era por isso que as fotos sumiam de todo mundo com o tempo — o `<img>` apontava
     * direto para um link vencido. Aqui o binário é baixado uma vez e fica no disco; a URL
     * do banco passa a ser só a origem, não o que a tela consome.
     *
     * NUNCA responde 404: sem foto, redireciona para o avatar desenhado (DiceBear) que o
     * servidor do front gera em `/_avatares/{seed}.svg`.
     * É o que permite a tela apontar todo `<img>` para cá sem saber de antemão quem tem
     * foto — não há estado quebrado, não há placeholder na tela, e a maioria (que não
     * tem foto visível) ganha um desenho próprio em vez do mesmo círculo azul de todos.
     */
    public function avatar(Conversation $conversation)
    {
        $path = Avatars::pathFor($conversation);

        if (! is_file($path)) {
            // Cache negativo: sem ele, uma conversa cuja foto morreu tentaria baixar de
            // novo a cada renderização da lista — centenas de requisições por tela.
            $semFoto = (bool) Cache::get("wa-avatar-miss:{$conversation->id}");

            if ($semFoto || ! Avatars::baixar($conversation)) {
                Cache::put("wa-avatar-miss:{$conversation->id}", true, now()->addHours(6));

                // Sem foto: manda para o avatar desenhado (DiceBear), que mora no
                // servidor do front — é biblioteca JS, o PHP não roda. Redirecionar em vez
                // de devolver a imagem mantém UMA url por contato na tela: quem chama não
                // precisa saber se existe foto, e o navegador guarda o destino.
                //
                // A seed é um HASH do slug, nunca o slug: a rota do front é pública e
                // slug de conversa é `wa-<telefone>`. O prefixo é a versão do estilo —
                // trocar o estilo muda a seed e fura o cache de um ano de todo mundo.
                $seed = 'a1-'.substr(hash('sha256', (string) $conversation->slug), 0, 16);

                /*
                 * O 302 saía SEM Cache-Control: o navegador voltava a perguntar a cada
                 * render. Num dia cheio medido (20/09), 27.520 dos 40.887 pedidos da API
                 * eram exatamente este redirecionamento — 67% de TODO o tráfego, no mesmo
                 * endpoint onde o PHP-FPM devolveu 502. O destino é hash determinístico do
                 * slug e nunca muda, então pode ser guardado por uma semana: é CPU que
                 * volta para a máquina sem trocar nada do que o usuário vê.
                 */
                return redirect()->away(rtrim((string) config('app.frontend_url'), '/')."/_avatares/{$seed}.svg", 302)
                    ->header('Cache-Control', 'public, max-age=604800, immutable');
            }
        }

        return response()->file($path, [
            'Content-Type' => Avatars::mimeDe($path),
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
