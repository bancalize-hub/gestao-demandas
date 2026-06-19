<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registra uma atividade na linha do tempo do lead. Usado tanto pelos eventos
     * automáticos (etapa/reunião/follow-up) quanto pela nota manual. Best-effort.
     */
    public static function log(int $conversationId, string $type, string $title, ?string $body = null, ?int $userId = null): ?self
    {
        try {
            return self::create([
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
