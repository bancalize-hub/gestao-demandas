<?php

use Illuminate\Auth\AuthenticationException;
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
        /*
         * Convidado em rota protegida: PARA ONDE o middleware acha que deve mandá-lo.
         *
         * O `Authenticate` monta esse destino ANTES de lançar a exceção — e o padrão do
         * framework é `route('login')`, rota que não existe num backend só-API. Resultado:
         * a requisição morria com `Route [login] not defined` (500) dentro do middleware,
         * sem nunca chegar ao handler que devolve 401. Era o que enchia o log: 98.690
         * linhas. O tratamento do handler (mais abaixo) só pegava quem mandava
         * `Accept: application/json`, porque aí o próprio middleware pula esta parte.
         *
         * Em /api/*: null — a exceção chega limpa ao handler, que responde 401 JSON.
         * Fora dela: o login do SPA, que é o único lugar onde alguém de fato loga.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*')
                ? null
                : rtrim((string) config('app.frontend_url'), '/').'/login'
        );

        // Habilita o guard de sessão (cookie) do Sanctum para requests do SPA.
        $middleware->statefulApi();

        // Teto geral do grupo `api` (limitador 'api' definido no AppServiceProvider).
        // As rotas públicas sensíveis têm tetos próprios, bem menores, em routes/api.php.
        $middleware->throttleApi();

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

        /*
         * Sessão expirada devolvia 500, não 401.
         *
         * O handler padrão de "não autenticado" tenta redirecionar para a rota `login`,
         * que não existe num backend que só serve API — a exceção virava
         * `Route [login] not defined`, 500 na cara do usuário e uma linha no log. Eram
         * 98.690 linhas assim num arquivo de 424 MB (87% de tudo que o log tinha), e o
         * front, recebendo 500, não sabia que era só o caso de mandar o usuário logar.
         */
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Sessão expirada. Entre novamente.'], 401);
            }

            return null;
        });
    })->create();
