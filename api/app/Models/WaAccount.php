<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um número de WhatsApp conectado (uma instância da Evolution).
 * O número principal (role=primary) atende anúncios com IA; os de prospecção
 * (role=outreach) fazem disparo/prospecção e têm respostas atendidas por humano.
 */
class WaAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'daily_cap' => 'integer',
        'warmup_day' => 'integer',
        'sent_today' => 'integer',
        'sent_date' => 'date',
        'last_sent_at' => 'datetime',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function isPrimary(): bool
    {
        return $this->role === 'primary';
    }

    /** A conta principal (atende anúncios). Cacheada por request. */
    public static function primary(): ?self
    {
        static $cached = null;

        return $cached ??= static::where('role', 'primary')->first();
    }

    /** Resolve a conta pelo nome da instância recebido no webhook da Evolution. */
    public static function byInstance(?string $instance): ?self
    {
        if (! $instance) {
            return null;
        }

        return static::where('instance', $instance)->first();
    }

    /**
     * Teto diário efetivo considerando o aquecimento: número novo começa baixo e
     * sobe a cada dia até atingir o daily_cap configurado.
     */
    public function effectiveDailyCap(): int
    {
        if ($this->daily_cap <= 0) {
            return 0;
        }
        // Rampa de aquecimento conservadora: 20 no 1º dia, +20/dia, até o teto.
        $warmCap = 20 * max(1, (int) $this->warmup_day);

        return min($this->daily_cap, $warmCap);
    }

    /** Quantos envios ainda cabem hoje neste número. */
    public function remainingToday(): int
    {
        $sent = $this->sent_date && $this->sent_date->isToday() ? $this->sent_today : 0;

        return max(0, $this->effectiveDailyCap() - $sent);
    }
}
