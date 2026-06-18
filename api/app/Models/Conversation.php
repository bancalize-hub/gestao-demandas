<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = [];

    /** Silencia o broadcast de tempo real (usado em importações em massa). */
    public static bool $muteBroadcast = false;

    protected $casts = [
        'online' => 'boolean',
        'hot' => 'boolean',
        'prob' => 'integer',
        'unread' => 'integer',
        'archived' => 'boolean',
        'auto_reply' => 'boolean',
        'auto_reply_due_at' => 'datetime',
        'tags' => 'array',
        'interactions' => 'array',
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

        // Tempo real: avisa os painéis abertos (chat/etiquetas/funil) a cada mudança.
        // Broadcast é best-effort: se o Reverb estiver fora, não derruba a operação.
        // Silenciável em importações em massa (self::$muteBroadcast) p/ não floodar.
        $notify = function () {
            if (self::$muteBroadcast) {
                return;
            }
            try {
                \App\Events\CrmUpdated::dispatch('conversation');
            } catch (\Throwable $e) {
            }
        };
        static::saved($notify);
        static::deleted($notify);
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
    public function lastMessage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('ts');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
