<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Habilita o guard de sessão (cookie) do Sanctum para requests do SPA.
        $middleware->statefulApi();

        // Vincula a empresa (tenant) do usuário autenticado. Usado no grupo auth:sanctum.
        $middleware->alias([
            'set.tenant' => \App\Http\Middleware\SetTenant::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'super.admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
        ]);

        // O SetTenant PRECISA rodar antes do route-model-binding (SubstituteBindings).
        // Sem isso, o binding resolve o {conversation} etc. sem tenant vinculado e o
        // CompanyScope fica inerte — devolvendo o registro de OUTRA empresa (mesmo
        // slug/id em empresas distintas). Colocá-lo na lista de prioridade logo antes
        // do SubstituteBindings garante a ordem: auth:sanctum -> set.tenant -> binding.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\SetTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
