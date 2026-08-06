<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Credencial da API do Facebook Ads. Segredos ficam com cast `encrypted`: quem
 * ler o banco direto não leva o token embora.
 */
class MarketingCredential extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'app_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'checked_at' => 'datetime',
    ];

    /** Nunca devolver segredo para a tela — só se está preenchido. */
    protected $hidden = ['app_secret', 'access_token'];

    protected $appends = ['tem_segredo'];

    public function getTemSegredoAttribute(): bool
    {
        return filled($this->access_token);
    }

    /** A credencial da instalação (uma por empresa; na prática, a sua). */
    public static function atual(): self
    {
        return static::firstOrCreate([], []);
    }
}
