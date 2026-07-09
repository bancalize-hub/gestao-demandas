<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'hot' => 'boolean',
        'won' => 'boolean',
        'tag_strong' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Tempo real: avisa os painéis (funil) a cada mudança. Best-effort.
        // Passa o company_id do próprio registro para o aviso ir só à empresa dona.
        $notify = function ($deal) {
            try {
                \App\Events\CrmUpdated::dispatch('deal', $deal->company_id);
            } catch (\Throwable $e) {
            }
        };
        static::saved($notify);
        static::deleted($notify);
    }
}
