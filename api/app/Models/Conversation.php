<?php

namespace App\Models;

use App\Events\CrmUpdated;
use App\Models\Concerns\BelongsToCompany;
use App\Services\ContactListSync;
use App\Services\StageAutomationEnqueuer;
use App\Support\MetaConversions;
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
        'last_message_at', 'unread', 'archived', 'hidden_at', 'auto_reply', 'in_memory', 'tags', 'phone',
        'email', 'company', 'origin', 'responsible', 'segmento', 'notes', 'interactions', 'company_id',
        // Dono do negócio: a ficha mostra e o funil filtra por ele. Fora daqui a coluna
        // existe no banco mas nunca chega ao front — foi o que faltava para a tela.
        'owner_user_id',
        // A triagem aparece no chip da lista e no cabeçalho, então tem de vir na lista.
        // São dois tinyint e uma frase curta — o motivo vem junto porque decidir sem ver
        // o porquê da marca da IA é o mesmo que não ter marca nenhuma.
        'qualified', 'qualified_reason', 'qualified_auto',
    ];

    /**
     * "Escondido pela triagem": reprovado (`qualified = 0`) E ainda parado na 1ª etapa do
     * funil E sem nenhuma reunião registrada.
     *
     * As duas exceções são de propósito. Reprovar é, na maioria das vezes, um PALPITE da IA
     * que ninguém confirmou, e o palpite erra para o lado do leigo — quem pergunta besteira
     * é desconhecimento, não lead ruim. Um lead que já andou no funil ou que ocupou a agenda
     * de alguém teve tempo de gente investido nele: some da tela é caro demais se o veredito
     * estiver errado, porque a lista é o único lugar onde alguém tropeça no erro. Lead novo
     * reprovado, que é a esmagadora maioria, sai da frente normalmente.
     *
     * A 1ª etapa vem por subquery da empresa DA CONVERSA, não do tenant da requisição: esta
     * conta também roda no push de tempo real (webhook/fila), onde nem sempre há empresa no
     * contexto — e ali um palpite errado esconderia lead da empresa errada.
     *
     * O `coalesce` garante 0/1 (nunca NULL): com etapa nula ou empresa sem funil, o
     * `not (...)` viraria NULL e a listagem apagaria a conversa da tela.
     */
    private static function sqlOcultoPelaTriagem(): string
    {
        return 'coalesce((conversations.qualified = 0'
            .' and conversations.stage = (select s.`key` from stages s'
            .' where s.company_id = conversations.company_id order by s.position, s.id limit 1)'
            .' and not exists (select 1 from meetings where meetings.conversation_id = conversations.id)), 0)';
    }

    /**
     * Query padrão da lista: colunas enxutas + última mensagem (só o necessário
     * p/ preview/✓✓) + ts da 1ª mensagem (filtro de data do Funil).
     *
     * Conversa escondida (`hidden_at`) fica de fora aqui, e é o único lugar que precisa
     * filtrar: a tela inteira — lista, funil, busca, encaminhar — deriva deste payload.
     *
     * DESQUALIFICADO some junto, pelo mesmo motivo do "excluir": tira da frente sem
     * apagar nada. A diferença é que aqui não há coluna de "escondido" — a régua é o
     * próprio veredito (`qualified = 0`), então basta a IA (ou uma pessoa) requalificar
     * o lead para ele voltar à lista sozinho. Ticks de triagem/resposta/retomada não
     * passam por aqui, então o lead escondido continua sendo atendido e reavaliado.
     *
     * Cada linha ainda carrega `triagem_oculta`: é a MESMA conta, exposta para a tela.
     * A lista do servidor já vem sem esses leads, mas o patch de tempo real precisa dizer
     * ao front que aquela conversa acabou de sair (ou voltar), e o front não tem como
     * refazer a conta sozinho — reunião não viaja na linha da lista.
     *
     * `$soExcluidas`: false = as ativas (padrão), true = só a lixeira, null = tanto faz.
     * O `null` é para linha única (patch de tempo real), onde filtrar significaria 404 e o
     * front apagaria da memória a conversa que a tela ainda está mostrando.
     */
    public static function listQuery(bool $comDesqualificados = false, ?bool $soExcluidas = false): Builder
    {
        $oculto = self::sqlOcultoPelaTriagem();
        $q = static::query();

        // Ou as ativas, ou a lixeira — nunca as duas juntas: misturar apagado com ativo na
        // mesma lista tira a única informação que a lixeira tem para dar.
        if ($soExcluidas === true) {
            $q->whereNotNull('conversations.hidden_at');
        } elseif ($soExcluidas === false) {
            $q->whereNull('conversations.hidden_at');
        }

        return $q
            ->unless($comDesqualificados, fn ($b) => $b->whereRaw("not {$oculto}"))
            // selectRaw depois do select: `select()` zera as colunas e levaria o campo junto.
            ->select(array_map(fn ($c) => "conversations.{$c}", self::LIST_COLUMNS))
            ->selectRaw("{$oculto} as triagem_oculta")
            ->with(['lastMessage' => fn ($q) => $q->select('messages.id', 'messages.conversation_id', 'messages.is_out', 'messages.ts')])
            ->withMin('messages as started_ts', 'ts');
    }

    protected $casts = [
        'proposal_sent_at' => 'datetime',
        'closed_at' => 'datetime',
        'ai_paused_until' => 'datetime',
        'last_touch_at' => 'datetime',
        'online' => 'boolean',
        'hot' => 'boolean',
        'prob' => 'integer',
        'unread' => 'integer',
        'archived' => 'boolean',
        'hidden_at' => 'datetime',
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

        // Lead APROVADO na triagem vira conversão para a Meta. Fica aqui, e não em quem
        // qualifica, porque são dois caminhos — a IA (QualificarTick) e o chip na tela —
        // e um evento que depende de alguém lembrar de chamá-lo é um evento que uma hora
        // para de sair. Só na SUBIDA para qualificado: desqualificar não desfaz conversão,
        // e requalificar não conta de novo (enviarQualificado é uma vez por conversa).
        static::updated(function (Conversation $c) {
            if (self::$muteBroadcast || ! $c->wasChanged('qualified') || $c->qualified !== true) {
                return;
            }
            try {
                MetaConversions::enviarQualificado($c);
            } catch (\Throwable $e) {
            }
        });

        // Listas automáticas acompanham a conversa: mudou a TRIAGEM ou a ETIQUETA, o lead
        // entra nas listas que passou a casar e SAI das que deixou de casar. Fica aqui pelo
        // mesmo motivo do gancho acima — são vários caminhos (IA, chip, menu de etiqueta,
        // arrastar no funil) e regra que depende de alguém lembrar de chamá-la uma hora
        // para de valer. Best-effort: lista certa nunca vale mais que a conversa gravada.
        static::updated(function (Conversation $c) {
            if (self::$muteBroadcast || ! $c->wasChanged(['qualified', 'stage'])) {
                return;
            }
            try {
                app(ContactListSync::class)->reconciliar($c);
            } catch (\Throwable $e) {
            }
        });
    }


    /**
     * Carimba o que ACABOU DE SAIR para o lead.
     *
     * `last_touch_at` responde "há quanto tempo ninguém fala com esse negócio" sem varrer
     * a tabela de mensagens (é o que o watchdog de negócio órfão consulta a cada rodada).
     *
     * `proposal_sent_at` só é carimbado uma vez, e de propósito com régua ESTREITA:
     * documento anexado, ou texto com contrato/proposta/orçamento/valor em reais/boleto/pix.
     * Palavra solta como "setup" aparece em conversa normal o tempo todo — usá-la encheria
     * a métrica de falso positivo justamente na única meta que o time precisa enxergar.
     */
    public function registrarSaida(?string $texto, ?string $tipo = 'text'): void
    {
        $campos = ['last_touch_at' => now()];

        $ehDocumento = in_array((string) $tipo, ['document', 'file', 'image'], true);
        $falaDeProposta = (bool) preg_match(
            '/\bcontratos?\b|\bpropostas?\b|\bor[çc]amentos?\b|R\$\s?\d|link de pagamento|\bboletos?\b|chave pix/iu',
            (string) $texto,
        );

        if (! $this->proposal_sent_at && ($ehDocumento || $falaDeProposta)) {
            $campos['proposal_sent_at'] = now();
        }

        $this->forceFill($campos)->save();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WaAccount::class, 'wa_account_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    /** Reuniões do contato, canceladas inclusive. Para "as que valem", use o escopo active(). */
    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
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
