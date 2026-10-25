<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Diário do piloto automático: cada decisão com o motivo, para ser auditável por gente. */
class MarketingAutopilotEvent extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = ['data' => 'array', 'simulated' => 'boolean'];
}
