<?php

namespace App\Models;

use App\Events\CrmUpdated;
use App\Models\Concerns\BelongsToCompany;
use App\Services\StageAutomationEnqueuer;
use App\Support\Wa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    /** Silencia o broadcast de tempo real (usado em importações em massa). */
    public static bool $muteBroadcast = false;

    /**
     * Colunas que a LISTA de conversas consome (index, evento de tempo real e
     * patch de conversa única). Fora ficam wa_jid/custom_fields/draft/timestamps —
     * com centenas de conversas o excesso inflava o payload em ~40%.
     */
    public const LIST_COLUMNS = [
        'id', 'slug', 'name', 'initials', 'avatar', 'online', 'status_text', 'role',
        'deal_value', 'deal_unit', 'stage', 'stage_color', 'prob', 'hot', 'preview', 'time',
        'last_message_at', 'unread', 'archived', 'auto_reply', 'in_memory', 'tags', 'phone',
        'email', 'company', 'origin', 'responsible', 'segmento', 'notes', 'interactions', 'company_id',
        // A triagem aparece no chip da lista e no cabeçalho, então tem de vir na lista.
        // São dois tinyint e uma frase curta — o motivo vem junto porque decidir sem ver
        // o porquê da marca da IA é o mesmo que não ter marca nenhuma.
        'qualified', 'qualified_reason', 'qualified_auto',
    ];

    /**
     * Query padrão da lista: colunas enxutas + última mensagem (só o necessário
     * p/ preview/✓✓) + ts da 1ª mensagem (filtro de data do Funil).
     */
    public static function listQuery(): Builder
    {
        return static::query()
            ->select(array_map(fn ($c) => "conversations.{$c}", self::LIST_COLUMNS))
            ->with(['lastMessage' => fn ($q) => $q->select('messages.id', 'messages.conversation_id', 'messages.is_out', 'messages.ts')])
            ->withMin('messages as started_ts', 'ts');
    }

    protected $casts = [
        'online' => 'boolean',
        'hot' => 'boolean',
        'prob' => 'integer',
        'unread' => 'integer',
        'archived' => 'boolean',
        'qualified' => 'boolean',
        'qualified_auto' => 'boolean',
        'qualified_at' => 'datetime',
        'auto_reply' => 'boolean',
        'auto_reply_due_at' => 'datetime',
        'nudge_count' => 'integer',
        'nudge_last_at' => 'datetime',
        'tags' => 'array',
        'interactions' => 'array',
        'custom_fields' => 'array',
    ];

    protected static function booted(): void
    {
        // Toda conversa nova entra na 1ª etapa do funil (ex.: "Lead") — vira um
        // negócio no pipeline automaticamente. A etiqueta espelha a etapa atual.
        static::creating(function (Conversation $c) {
            if (empty($c->stage)) {
                $first = Stage::orderBy('position')->orderBy('id')->first();
                if ($first) {
                    $c->stage = $first->key;
                    $c->stage_color = $c->stage_color ?: $first->color;
                    if (empty($c->tags)) {
                        $c->tags = [['label' => $first->name, 'color' => $first->color]];
                    }
                }
            }
        });

        // Tempo real: avisa os painéis abertos (chat/etiquetas/funil) quando a
        // conversa muda de verdade. Broadcast é best-effort: se o Reverb estiver
        // fora, não derruba a operação. Silenciável em massa (self::$muteBroadcast).
        static::saved(function (Conversation $conv) {
            if (self::$muteBroadcast) {
                return;
            }
            // Mudanças "quietas" não broadcastam: o realtime delas já viaja no
            // MessageCreated (preview/time/last_message_at) ou é ação do próprio
            // cliente (unread=0 ao abrir o chat). Antes, o PATCH de unread gerava
            // broadcast → o próprio cliente re-baixava lista+thread ao ABRIR a conversa.
            $quiet = ['unread', 'time', 'preview', 'last_message_at', 'auto_reply_due_at', 'online', 'status_text', 'updated_at', 'nudge_count', 'nudge_last_at'];
            if (! $conv->wasRecentlyCreated && empty(array_diff(array_keys($conv->getChanges()), $quiet))) {
                return;
            }
            try {
                broadcast(new CrmUpdated('conversation', $conv->company_id, $conv->slug))->toOthers();
            } catch (\Throwable $e) {
            }
        });
        static::deleted(function (Conversation $conv) {
            if (self::$muteBroadcast) {
                return;
            }
            try {
                broadcast(new CrmUpdated('conversation', $conv->company_id, $conv->slug))->toOthers();
            } catch (\Throwable $e) {
            }
        });

        // Playbook por etapa: ao ENTRAR numa etapa (mudança real de stage, não na
        // criação), enfileira as mensagens automáticas dessa etapa. Best-effort:
        // nunca derruba o save da conversa. Silenciado em importações em massa.
        static::updated(function (Conversation $c) {
            if (self::$muteBroadcast || ! $c->wasChanged('stage')) {
                return;
            }
            try {
                StageAutomationEnqueuer::onEnterStage($c);
            } catch (\Throwable $e) {
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WaAccount::class, 'wa_account_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    /**
     * Timestamp (unix) da última mensagem RECEBIDA do cliente — o marco que abre a
     * janela de 24h da API oficial. null = o cliente nunca escreveu.
     */
    public function lastInboundTs(): ?int
    {
        $ts = $this->messages()->reorder()->where('is_out', false)->max('ts');

        return $ts ? (int) $ts : null;
    }

    /**
     * Dá para mandar texto livre agora? Na Evolution, sempre; na Cloud API, só dentro
     * das 24h desde a última mensagem do cliente (fora disso, só template aprovado).
     */
    public function canSendFreeform(): bool
    {
        return Wa::forConversation($this)->canSendFreeform($this->lastInboundTs());
    }

    public function messages(): HasMany
    {
        // Ordena cronologicamente pelo timestamp real (ts), com id como desempate.
        // NÃO usar 'position' como chave primária de ordenação: ela podia ficar em
        // ordem decrescente e, por ser prepended em toda query, travava os reindex
        // por ts (que só conseguiam re-carimbar a ordem errada). ts é preenchido em
        // todos os fluxos (webhook, import, envio manual), então é a fonte da verdade.
        return $this->hasMany(Message::class)->orderByRaw('ts IS NULL, ts')->orderBy('id');
    }

    /** Só a última mensagem (cronológica) — usada na LISTA de conversas, sem carregar a thread toda. */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('ts');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
