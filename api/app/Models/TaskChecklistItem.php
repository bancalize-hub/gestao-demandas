<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de checklist do cartão. Sem company_id de propósito: só se chega aqui pela
 * tarefa (rota aninhada), e a tarefa já é filtrada por empresa pelo CompanyScope.
 */
class TaskChecklistItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'done' => 'boolean',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
