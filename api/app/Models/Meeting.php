<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Meeting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'reminder_lead_minutes' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
