<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Restringe rotas ao ADMIN da empresa (o super-admin também passa). É o gate das
 * áreas de gestão por empresa — ex.: marketing (Facebook Ads), cuja credencial e
 * dados são todos por empresa (BelongsToCompany), diferente do grupo super.admin,
 * que opera a plataforma inteira.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        abort_unless((bool) ($u?->is_admin || $u?->is_super_admin), 403, 'Acesso restrito ao admin da empresa.');

        return $next($request);
    }
}
