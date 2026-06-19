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

// Transcrição de áudios recebidos (Groq Whisper) → a IA "ouve" os áudios do cliente.
Schedule::command('voice:transcribe-tick')
    ->everyMinute()
    ->withoutOverlapping();

// Atendimento automático: a IA responde sozinha os leads das conversas com auto_reply ligado.
Schedule::command('auto-reply:tick')
    ->everyMinute()
    ->withoutOverlapping();

// Lembrete de reunião: avisa o cliente pelo WhatsApp X min antes de começar (padrão 1h).
Schedule::command('meetings:remind-tick')
    ->everyMinute()
    ->withoutOverlapping();

// Follow-up vencido: a IA gera um rascunho de mensagem de retomada (o vendedor é quem envia).
Schedule::command('followup:tick')
    ->everyMinute()
    ->withoutOverlapping();

// Importa reuniões com Meet criadas direto no Google Agenda → tabela meetings (casa com o lead),
// para o pós-reunião também processá-las. Roda antes do attendance-tick.
Schedule::command('meetings:calendar-sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Pós-reunião: apura presença no Meet (move p/ "Reunião Realizada") e anexa o resumo do read.ai.
Schedule::command('meetings:attendance-tick')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
