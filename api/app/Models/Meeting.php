<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Meeting extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reminder_lead_minutes' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Reuniões que ainda valem: não canceladas. É a base da regra de ouro do
     * agendamento (no máximo UM agendamento ativo por contato).
     */
    public function scopeNotCancelled($query)
    {
        return $query->whereNull('cancelled_at');
    }

    /** Ativa = não cancelada e ainda por acontecer. */
    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at')->where('starts_at', '>', now());
    }

    /** O agendamento ativo de um contato (o mais próximo), ou null. */
    public static function activeFor(int $conversationId): ?self
    {
        return static::where('conversation_id', $conversationId)
            ->active()
            ->orderBy('starts_at')
            ->first();
    }
}
