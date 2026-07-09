<?php

namespace App\Models\Scopes;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope que isola os dados por empresa.
 *
 * Só filtra quando há uma empresa vinculada ao contexto (Tenancy::check()).
 * Sem empresa vinculada (agendador, console, webhook antes de resolver a
 * instância) o escopo é inerte e as consultas enxergam todas as empresas —
 * é responsabilidade desses fluxos vincular a empresa certa por linha.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->check()) {
            // Coluna qualificada para evitar ambiguidade em joins.
            $builder->where($model->getTable().'.company_id', $tenancy->id());
        }
    }
}
