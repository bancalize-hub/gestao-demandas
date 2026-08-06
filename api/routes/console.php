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

// Listas automáticas: o lead novo entra sozinho na lista que casa com o critério dela
// (e o lead de anúncio que só ganhou telefone depois é recuperado aqui).
Schedule::command('lists:sync')
    ->everyFifteenMinutes()
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

// Retomada ativa: a IA vai atrás do lead que sumiu no meio da conversa (até 3 mensagens
// com espaçamento crescente, só em horário comercial). A cada 5 min basta — o gatilho é
// silêncio de horas/dias, não a mensagem que acabou de chegar.
Schedule::command('nudge:tick')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Lembrete de reunião: avisa o cliente pelo WhatsApp X min antes de começar (padrão 1h).
Schedule::command('meetings:remind-tick')
    ->everyMinute()
    ->withoutOverlapping();

// Follow-up vencido: a IA gera um rascunho de mensagem de retomada (o vendedor é quem envia).
Schedule::command('followup:tick')
    ->everyMinute()
    ->withoutOverlapping();

// Campanhas de prospecção/disparo: envia a próxima mensagem respeitando o anti-ban
// conservador (intervalo aleatório por número, teto diário com aquecimento, janela).
Schedule::command('campaigns:tick')
    ->everyMinute()
    ->withoutOverlapping();

// Playbook por etapa: dispara as mensagens automáticas (PDF/follow-up) que venceram
// quando um lead entrou numa etapa configurada (ex.: proposta após "Reunião Realizada").
Schedule::command('automations:tick')
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
