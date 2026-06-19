<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageAutomationRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'run_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(StageAutomationStep::class, 'step_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
