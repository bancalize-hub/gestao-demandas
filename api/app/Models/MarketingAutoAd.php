<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um anúncio que o piloto automático pôs no ar. Guarda a idade (vida útil de criativo
 * nesta conta é 5–7 dias) e o motivo da pausa, que é o que o painel mostra.
 */
class MarketingAutoAd extends Model
{
    use BelongsToCompany;

    public const ATIVO = 'ativo';

    public const PAUSADO = 'pausado';

    protected $guarded = [];

    protected $casts = [
        'copy' => 'array',
        'daily_budget' => 'float',
        'launched_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    public function creative(): BelongsTo
    {
        return $this->belongsTo(MarketingCreative::class, 'marketing_creative_id');
    }
}
