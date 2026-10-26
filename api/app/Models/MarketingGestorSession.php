<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Um turno do agente gestor (ou do designer): o que ele olhou, o que fez, o que concluiu. */
class MarketingGestorSession extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'simulated' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'passos' => 'array',
    ];
}
