<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageAutomationStep extends Model
{
    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
        'delay_minutes' => 'integer',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(StageAutomation::class, 'automation_id');
    }
}
