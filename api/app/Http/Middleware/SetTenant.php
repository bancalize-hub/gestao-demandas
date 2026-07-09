<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vincula a empresa do usuário autenticado ao contexto de tenancy.
 *
 * Deve rodar depois de 'auth:sanctum'. A partir daqui, todo model de domínio
 * (BelongsToCompany) fica automaticamente filtrado pela empresa do usuário e
 * novos registros nascem carimbados com o company_id certo.
 *
 * Empresa inativa (ex.: suspensa) é bloqueada com 403.
 */
class SetTenant
{
    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->company_id) {
            $company = $user->company; // sem escopo (Company é a raiz da tenancy)

            if ($company && ! $company->is_active) {
                abort(403, 'Empresa suspensa. Contate o suporte.');
            }

            $this->tenancy->set($user->company_id);
        }

        return $next($request);
    }
}
