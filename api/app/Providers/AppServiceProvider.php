<?php

namespace App\Providers;

use App\Support\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Contexto de tenancy vive pelo tempo do processo (uma request/console por vez).
        $this->app->singleton(Tenancy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->rateLimiters();
    }

    /**
     * Tetos por rota pública. Sem eles, /login e /register aceitam tentativa infinita
     * do mesmo IP — e o portal do cliente aceita enchendo o quadro de tarefas de graça.
     *
     * Os números são folgados de propósito: o objetivo é cortar automação, não atrapalhar
     * quem erra a senha duas vezes.
     */
    private function rateLimiters(): void
    {
        // Força bruta de senha. A chave junta IP e e-mail: trocar de e-mail não zera o
        // contador do IP, e o mesmo e-mail atacado de vários IPs também é travado.
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by('ip:'.$r->ip()),
            Limit::perMinute(5)->by('email:'.mb_strtolower((string) $r->input('email'))),
        ]);

        // Cadastro self-service: cada acerto cria UMA EMPRESA nova no banco.
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(3)->by($r->ip()));

        // Portal público de solicitações (sem login): o cliente legítimo abre uma ou duas.
        RateLimiter::for('solicitacoes', fn (Request $r) => Limit::perHour(10)->by($r->ip()));

        // Webhook de WhatsApp: teto ALTO, só para conter laço infinito de terceiro.
        // Uma rajada real de importação passa fácil por aqui.
        RateLimiter::for('webhook', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));

        // Rede geral do app autenticado. 600/min por usuário é ~10 por segundo: o SPA com
        // quatro chats abertos e refetch de tempo real fica MUITO abaixo disso, e um
        // raspador de dados não.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(600)
            ->by($r->user()?->id ? 'u:'.$r->user()->id : 'ip:'.$r->ip()));
    }
}
