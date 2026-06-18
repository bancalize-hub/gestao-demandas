<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'hot' => 'boolean',
        'won' => 'boolean',
        'tag_strong' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Tempo real: avisa os painéis (funil) a cada mudança. Best-effort.
        $notify = function () {
            try {
                \App\Events\CrmUpdated::dispatch('deal');
            } catch (\Throwable $e) {
            }
        };
        static::saved($notify);
        static::deleted($notify);
    }
}
