<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração do agente SDR (uma por empresa): ligado ou não, o ritmo que ele mesmo marca e
 * os limites anti-spam. Nasce desligado — ligar é decisão de gente, pelo painel.
 */
class SdrAgent extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'max_unanswered' => 'integer',
        'min_hours_between' => 'integer',
        'daily_cap' => 'integer',
        'per_turn_cap' => 'integer',
        'learnings' => 'array',
        'run_requested_at' => 'datetime',
        'last_review_at' => 'datetime',
        'next_review_at' => 'datetime',
    ];

    public static function atual(): self
    {
        $cfg = static::firstOrCreate([], []);

        // Recém-criado, o model não conhece os padrões do banco (os limites): sem recarregar,
        // a primeira rodada leria tudo null — e limite null é limite nenhum.
        return $cfg->wasRecentlyCreated ? $cfg->fresh() : $cfg;
    }
}
