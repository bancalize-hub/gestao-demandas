<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empresa (tenant). Cada empresa atende seus próprios clientes com seus próprios
 * números de WhatsApp, totalmente isolada das demais.
 *
 * Este model NÃO usa BelongsToCompany — é a raiz da tenancy. Consultá-lo sempre
 * enxerga todas as empresas (uso restrito a fluxos de plataforma / super-admin).
 */
class Company extends Model
{
    protected $guarded = [];

    // URLs completas das logos são expostas ao front (o path fica no banco).
    protected $appends = ['logo_light_url', 'logo_dark_url'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_reply_new_leads' => 'boolean',
        ];
    }

    protected function logoLightUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo_light ? asset('storage/'.$this->logo_light) : null);
    }

    protected function logoDarkUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo_dark ? asset('storage/'.$this->logo_dark) : null);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function waAccounts(): HasMany
    {
        return $this->hasMany(WaAccount::class);
    }
}
