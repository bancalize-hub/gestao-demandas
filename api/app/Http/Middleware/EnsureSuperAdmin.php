<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe rotas ao dono da plataforma (super-admin).
 *
 * Recursos que operam a própria VPS (ex.: /agente, que dirige o Claude Code no
 * servidor) NUNCA podem ser expostos às empresas-clientes.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403, 'Acesso restrito à plataforma.');

        return $next($request);
    }
}
