<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'min_gap_s' => 'integer',
        'max_gap_s' => 'integer',
        'daily_cap' => 'integer',
        'total' => 'integer',
        'sent' => 'integer',
        'replied' => 'integer',
        'failed' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(WaAccount::class, 'wa_account_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CampaignContact::class);
    }

    /** Recalcula os contadores a partir dos contatos (chamar após mudanças em lote). */
    public function refreshCounts(): void
    {
        $this->update([
            'total' => $this->contacts()->count(),
            'sent' => $this->contacts()->whereIn('status', ['sent', 'replied'])->count(),
            'replied' => $this->contacts()->where('status', 'replied')->count(),
            'failed' => $this->contacts()->where('status', 'failed')->count(),
        ]);
    }
}
