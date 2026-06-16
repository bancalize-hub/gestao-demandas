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
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Habilita o guard de sessão (cookie) do Sanctum para requests do SPA.
        $middleware->statefulApi();

        // TEMPORÁRIO (Fase 1): as rotas do CRM ainda são públicas e o front
        // atual não envia X-XSRF-TOKEN. Isenta-as do CSRF até a Fase 2, quando
        // o front passar a fazer o fluxo Sanctum e elas irão para auth:sanctum.
        $middleware->validateCsrfTokens(except: [
            'api/conversations',
            'api/conversations/*',
            'api/deals',
            'api/deals/*',
            'api/tasks',
            'api/tasks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
