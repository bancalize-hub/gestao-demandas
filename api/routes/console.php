<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronização automática do WhatsApp: puxa do Evolution o que falta (mensagens
// que escaparam do webhook + histórico) e enriquece nome/foto pelos contatos.
// Idempotente; o tempo real (mensagens novas) já vem pelo webhook instantaneamente.
// Contatos do Google → tabela local (a cada 30 min). Roda antes do backfill.
Schedule::command('contacts:sync')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('wpp:backfill')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Atendimento automático: a IA responde sozinha os leads das conversas com auto_reply ligado.
Schedule::command('auto-reply:tick')
    ->everyMinute()
    ->withoutOverlapping();
