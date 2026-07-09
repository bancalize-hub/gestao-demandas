<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy;

/**
 * Aplica-se a todo model de domínio isolado por empresa.
 *
 *  - Adiciona o CompanyScope (filtra por company_id quando há empresa vinculada).
 *  - No `creating`, carimba company_id a partir da empresa atual, se ainda vazio.
 *
 * Todos os models usam $guarded = [], então o carimbo no evento `creating`
 * cobre inclusive criações via mass-assignment sem precisar de $fillable.
 */
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            if (empty($model->company_id)) {
                $tenancy = app(Tenancy::class);
                if ($tenancy->check()) {
                    $model->company_id = $tenancy->id();
                }
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
