<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToCompany;

    /**
     * Colunas que o chat realmente consome (thread e eventos de tempo real).
     * Fora ficam position/transcribe_attempts/timestamps — em threads de milhares
     * de mensagens esse excesso dobrava o JSON do /full.
     */
    public const THREAD_COLUMNS = [
        'id', 'conversation_id', 'wa_id', 'type', 'is_out', 'status', 'text', 'transcript',
        'time', 'ts', 'dur', 'file_name', 'meta', 'label', 'reply_to', 'reply_excerpt', 'reaction',
    ];

    protected $guarded = [];

    protected $casts = [
        'is_out' => 'boolean',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
