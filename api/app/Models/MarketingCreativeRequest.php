<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Pedido do gestor de tráfego ao designer: o briefing e o que foi entregue. */
class MarketingCreativeRequest extends Model
{
    use BelongsToCompany;

    public const PENDENTE = 'pendente';

    public const PRODUZINDO = 'produzindo';

    public const PRONTO = 'pronto';

    public const FALHOU = 'falhou';

    protected $guarded = [];

    protected $casts = ['referencias' => 'array', 'evitar' => 'array', 'creative_ids' => 'array'];
}
