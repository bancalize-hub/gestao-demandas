<?php

namespace App\Models;

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

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
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
