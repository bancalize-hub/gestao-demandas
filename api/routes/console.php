<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

// Fotos de perfil dos contatos → binário no disco. Precisa de um número Evolution
// conectado (a API oficial da Meta não entrega foto de contato); sem ele o comando
// só avisa e sai. De hora em hora, 200 conversas por vez, das mais recentes para trás.
Schedule::command('wa:avatars --limite=200')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Transcrição de áudios recebidos (Groq Whisper) → a IA "ouve" os áudios do cliente.
Schedule::command('voice:transcribe-tick')
    ->everyMinute()
    ->withoutOverlapping();

// Atendimento automático: a IA responde sozinha os leads das conversas com auto_reply ligado.
// RELIGADO EM 16/08/2026, com a conta da Meta destravada (template de teste entregue, sem
// 131031). O despejo que se temia não aconteceu: a guarda de `stale_hours` já tinha vencido
// as pendências represadas, e as conversas de fato órfãs foram reabertas uma a uma, por
// template, pelo `wa:repescagem` — fora da janela de 24h texto livre nem sai.
Schedule::command('auto-reply:tick')
    ->everyMinute()
    ->withoutOverlapping();

// Retomada ativa: a IA vai atrás do lead que sumiu no meio da conversa (até 3 mensagens
// com espaçamento crescente, só em horário comercial). A cada 5 min basta — o gatilho é
// silêncio de horas/dias, não a mensagem que acabou de chegar.
//
// SEGUE PAUSADA DESDE 14/08/2026 (decisão do Paulo em 16/08). Foi este tick que gerou o
// padrão que a Meta leu como "Sending spam" e travou a conta por 30 dias: 95-107 conversas
// por dia recebendo 2+ mensagens depois de o lead parar de responder. Enquanto a punição
// estiver no histórico da conta, insistir com quem sumiu é o risco que não vale a pena.
// PARA RELIGAR: descomentar abaixo e `pm2 restart gestao-scheduler` — de preferência com
// teto menor que a escada de 3 degraus de hoje.
// Schedule::command('nudge:tick')
//     ->everyFiveMinutes()
//     ->withoutOverlapping();

// Pré-triagem dos leads pela IA (qualificado / desqualificado), que alimenta o custo por
// lead qualificado no painel de marketing. A cada 3 min: o valor está em a triagem
// acompanhar o gasto do anúncio, mas ninguém decide orçamento no intervalo de 1 minuto —
// e cada rodada sobe um processo do CLI numa VPS de 2 vCPU.
Schedule::command('leads:qualificar-tick')
    ->everyThreeMinutes()
    ->withoutOverlapping();

// Saúde de cada número de WhatsApp: conexão caída, número que ficou mudo e entrega com
// erro. Existe porque o número oficial ficou dois dias sem receber (19-21/09/2026) e quem
// descobriu foi uma auditoria — o painel de saúde é PULL e só super-admin abre.
// A cada 5 min: o que ele vigia muda em escala de horas, e cada rodada sobe um processo
// do CLI numa VPS de 2 vCPU.
Schedule::command('wa:health-tick')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);

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
// RELIGADO EM 16/08/2026: os 5 disparos represados estão todos fora da janela de 24h, e o
// tick marca esses como "pulado — janela fechada" em vez de enviar. Quem precisava de fato
// ser reaberto (JB, filha de Deus, Alvaro Lima, Luciano, GRUPO TITAN) foi por template no
// `wa:repescagem`; o material do playbook vai quando o lead responder.
Schedule::command('automations:tick')
    ->everyMinute()
    ->withoutOverlapping();

// Cliente sozinho na sala: avisa a equipe ENQUANTO a reunião acontece, não depois. A cada
// 5 min a reunião em curso é checada entre o 4º e o 20º minuto — 3 a 4 checagens por
// reunião, o suficiente para salvar a call sem martelar a API do Meet.
Schedule::command('meetings:noshow-tick')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Watchdog dos negócios quentes: cobra dono, proposta e silêncio (cria tarefa no quadro,
// nunca fala com o cliente). De hora em hora, com teto de 15 tarefas por rodada para não
// enterrar o time — na 1ª simulação havia 227 cobranças pendentes de uma vez.
Schedule::command('deals:watchdog-tick')
    ->hourly()
    ->withoutOverlapping();

// Rotação de criativo pela qualificação do CRM + alerta de saldo da conta pré-paga.
// Uma vez por dia, de manhã, com teto de 2 pausas — para a decisão ser revisável por gente.
Schedule::command('fbads:rotacao-tick --aplicar --max-pausas=2')
    ->dailyAt('09:10')
    ->withoutOverlapping()
    ->runInBackground();

// Transcrição que chegou atrasada (o Google publica minutos ou horas depois) e a auditoria
// de condução das calls. À noite: cada análise é uma chamada de IA com a call inteira.
Schedule::command('meetings:transcrever --desde=2026-08-01')
    ->dailyAt('21:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('meetings:analisar-calls --limite=6')
    ->dailyAt('22:00')
    ->withoutOverlapping()
    ->runInBackground();

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

// Batimento do agendador: o painel do super-admin usa isto para dizer se o scheduler
// está vivo. Sem ele, "os ticks pararam" só é descoberto quando alguém repara que a IA
// não responde mais — e não dá para perguntar ao pm2, que é do root.
// Grava STRING, não objeto: valor de cache com objeto dentro volta como
// __PHP_Incomplete_Class em alguns processos e quebraria justamente a tela que se olha
// quando o resto já está quebrado.
Schedule::call(fn () => Cache::put('super.heartbeat', now()->toIso8601String(), 3600))
    ->everyMinute()
    ->name('super-heartbeat')
    ->withoutOverlapping();
