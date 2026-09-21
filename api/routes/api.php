<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AiCredentialController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\ChatTabController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ContactListController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FollowUpController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\LeadActivityController;
use App\Http\Controllers\Api\MarketingController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\QuickReplyController;
use App\Http\Controllers\Api\StageAutomationController;
use App\Http\Controllers\Api\StageController;
use App\Http\Controllers\Api\SuperController;
use App\Http\Controllers\Api\TaskCardController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WhatsAppCloudController;
use App\Http\Controllers\Api\WhatsAppController;
use Illuminate\Support\Facades\Route;

// --- Autenticação (Sanctum SPA cookie) ---
Route::post('/login', [AuthController::class, 'login']);

// --- Público: cadastro self-service (cria a empresa + usuário dono e já autentica) ---
Route::post('/register', [AuthController::class, 'register']);

// --- Público: canal do cliente (portal de solicitações, sem login) ---
Route::post('/solicitacoes', [TaskController::class, 'store']);

// --- Público: branding da empresa por slug (cor + logos) p/ o portal do cliente ---
Route::get('/public/branding/{slug}', [BrandingController::class, 'publicBranding']);
// --- Público: marca da empresa PRINCIPAL (dona da instância) p/ login/cadastro ---
Route::get('/public/branding', [BrandingController::class, 'primaryBranding']);

// --- Público: webhook do Evolution (protegido por token na query) ---
Route::post('/wpp/webhook', [WhatsAppController::class, 'webhook']);

// --- Público: webhook da API OFICIAL (Meta). GET = handshake do painel da Meta;
//     POST = eventos, autenticados pela assinatura HMAC do corpo (app secret). ---
Route::get('/wpp/cloud/webhook', [WhatsAppCloudController::class, 'verify']);
Route::post('/wpp/cloud/webhook', [WhatsAppCloudController::class, 'webhook']);

// --- Público: callback do OAuth do Google (usuário identificado pelo state assinado) ---
Route::get('/google/callback', [GoogleAuthController::class, 'callback']);

// --- Protegido (auth:sanctum + tenancy: isola tudo pela empresa do usuário) ---
Route::middleware(['auth:sanctum', 'set.tenant'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::patch('/me/theme', [AuthController::class, 'updateTheme']);

    // Branding da empresa: cor de destaque + logos (alteração só admin, checado no controller).
    Route::get('/branding', [BrandingController::class, 'show']);
    Route::patch('/branding', [BrandingController::class, 'update']);

    // Horário de atendimento: quando a IA pode ABORDAR (retomada, reunião, campanha).
    Route::get('/attendance', [AttendanceController::class, 'show']);
    Route::patch('/attendance', [AttendanceController::class, 'update']);
    Route::post('/branding/logo', [BrandingController::class, 'uploadLogo']);
    Route::delete('/branding/logo', [BrandingController::class, 'removeLogo']);

    // Cadastro restrito: somente admin gerencia usuários.
    Route::get('/users', [AuthController::class, 'index']);
    Route::post('/users', [AuthController::class, 'store']);

    // CRM
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::get('/conversations/{conversation}/avatar', [ConversationController::class, 'avatar']);
    Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
    // "Excluir" = esconder (nada é apagado); restore é o Desfazer do aviso na tela.
    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy']);
    Route::post('/conversations/{conversation}/restore', [ConversationController::class, 'restore']);
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::post('/conversations/{conversation}/media', [MessageController::class, 'storeMedia']);
    Route::post('/conversations/{conversation}/messages/{message}/react', [MessageController::class, 'react']);
    Route::post('/conversations/{conversation}/messages/{message}/resend', [MessageController::class, 'resend']);
    Route::post('/conversations/{conversation}/forward', [MessageController::class, 'forward']);
    Route::delete('/conversations/{conversation}/messages/{message}', [MessageController::class, 'destroy']);
    Route::post('/conversations/{conversation}/suggest-reply', [ConversationController::class, 'suggestReply']);
    Route::post('/conversations/{conversation}/schedule-meeting', [ConversationController::class, 'scheduleMeeting']);

    // Linha do tempo do lead (atividades: notas manuais + eventos automáticos).
    Route::get('/stats/today', [ConversationController::class, 'todayStats']);
    Route::get('/conversations/{conversation}/activities', [LeadActivityController::class, 'index']);
    Route::post('/conversations/{conversation}/activities', [LeadActivityController::class, 'store']);
    Route::delete('/conversations/{conversation}/activities/{activity}', [LeadActivityController::class, 'destroy']);

    // Follow-ups de acompanhamento (tarefas type='followup' ligadas à conversa).
    Route::get('/conversations/{conversation}/followups', [FollowUpController::class, 'index']);
    Route::post('/conversations/{conversation}/followups', [FollowUpController::class, 'store']);
    Route::patch('/followups/{task}/complete', [FollowUpController::class, 'complete']);

    // Agente operacional (chat que dirige o Claude Code na VPS). Opera o próprio
    // servidor — EXCLUSIVO do dono da plataforma (super-admin). Nunca exposto às empresas.
    Route::middleware('super.admin')->group(function () {
        // Credencial da IA: é do SERVIDOR (todas as empresas usam a mesma assinatura).
        Route::get('/ai/status', [AiCredentialController::class, 'status']);
        Route::post('/ai/token', [AiCredentialController::class, 'salvar']);
        Route::post('/ai/test', [AiCredentialController::class, 'testar']);
        Route::post('/ai/login/start', [AiCredentialController::class, 'loginIniciar']);
        Route::post('/ai/login/finish', [AiCredentialController::class, 'loginConcluir']);

        Route::get('/agent/sessions', [AgentController::class, 'index']);
        Route::post('/agent/sessions', [AgentController::class, 'store']);
        Route::get('/agent/sessions/{session}', [AgentController::class, 'show']);
        Route::delete('/agent/sessions/{session}', [AgentController::class, 'destroy']);
        Route::post('/agent/sessions/{session}/messages', [AgentController::class, 'message']);
        Route::get('/agent/jobs/{job}', [AgentController::class, 'job']);
        Route::post('/agent/jobs/{job}/stop', [AgentController::class, 'stop']);

        // Painel da PLATAFORMA (página /super): fala de todas as empresas ao mesmo tempo,
        // por isso vive aqui dentro e nunca no grupo comum. Ver PLANO-SUPER-ADMIN.md.
        Route::get('/super/companies', [SuperController::class, 'empresas']);
        Route::patch('/super/companies/{company}', [SuperController::class, 'alternarEmpresa']);
        Route::get('/super/wa-accounts', [SuperController::class, 'numeros']);
        Route::patch('/super/wa-accounts/{conta}', [SuperController::class, 'alternarNumero']);
        Route::get('/super/platform', [SuperController::class, 'plataforma']);
    });

    // Marketing (Facebook Ads): credencial, criativos, campanhas e memória — tudo POR
    // EMPRESA (BelongsToCompany), por isso o gate é o admin da empresa, não o super-admin.
    Route::middleware('admin')->group(function () {
        Route::get('/marketing/status', [MarketingController::class, 'status']);
        Route::post('/marketing/credentials', [MarketingController::class, 'salvar']);
        Route::post('/marketing/test', [MarketingController::class, 'testar']);
        Route::get('/marketing/creatives', [MarketingController::class, 'criativos']);
        Route::get('/marketing/creatives-desempenho', [MarketingController::class, 'desempenhoCriativos']);
        Route::post('/marketing/creatives', [MarketingController::class, 'subirCriativo']);
        Route::patch('/marketing/creatives/{creative}', [MarketingController::class, 'atualizarCriativo']);
        Route::delete('/marketing/creatives/{creative}', [MarketingController::class, 'apagarCriativo']);
        Route::get('/marketing/creatives/{creative}/arquivo', [MarketingController::class, 'arquivo']);
        // Painel de gestão de campanhas do Facebook Ads (métricas, ativar/pausar, orçamento).
        Route::get('/marketing/campanhas', [MarketingController::class, 'campanhas']);
        Route::get('/marketing/metricas', [MarketingController::class, 'metricasFb']);
        Route::get('/marketing/ad-stats', [MarketingController::class, 'adStats']);
        Route::get('/marketing/otimizacao', [MarketingController::class, 'otimizacao']);
        Route::patch('/marketing/campanhas/{id}', [MarketingController::class, 'atualizarCampanha']);
        Route::post('/marketing/campanhas/{id}/duplicar', [MarketingController::class, 'duplicarCampanha']);
        // Memória de marketing: aprendizado sobre os anúncios, separado da memória da IA de vendas.
        Route::get('/marketing/memorias', [MarketingController::class, 'memorias']);
        Route::post('/marketing/memorias', [MarketingController::class, 'salvarMemoria']);
        Route::patch('/marketing/memorias/{memoria}', [MarketingController::class, 'atualizarMemoria']);
        Route::delete('/marketing/memorias/{memoria}', [MarketingController::class, 'apagarMemoria']);
    });

    Route::get('/stages', [StageController::class, 'index']);
    Route::post('/stages', [StageController::class, 'store']);
    Route::post('/stages/reorder', [StageController::class, 'reorder']);
    Route::patch('/stages/{stage}', [StageController::class, 'update']);
    Route::delete('/stages/{stage}', [StageController::class, 'destroy']);

    // Playbook por etapa: mensagens automáticas (PDF/follow-up) ao entrar numa etapa.
    // Configurações gerais de automação (IA automática p/ leads novos) — alteração só admin.
    Route::get('/automation-settings', [StageAutomationController::class, 'settings']);
    Route::patch('/automation-settings', [StageAutomationController::class, 'updateSettings']);
    Route::get('/stage-automations', [StageAutomationController::class, 'index']);
    Route::post('/stage-automations', [StageAutomationController::class, 'store']);
    Route::post('/stage-automations/steps/{step}/asset', [StageAutomationController::class, 'uploadAsset']);
    Route::delete('/stage-automations/{stageAutomation}', [StageAutomationController::class, 'destroy']);

    Route::get('/deals', [DealController::class, 'index']);
    Route::post('/deals', [DealController::class, 'store']);
    Route::patch('/deals/{deal}', [DealController::class, 'update']);
    Route::delete('/deals/{deal}', [DealController::class, 'destroy']);

    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);

    // Quadro de tarefas (/tarefas) — a moldura: listas, etiquetas, time e cartões.
    Route::get('/board', [BoardController::class, 'index']);
    Route::get('/board/archived', [BoardController::class, 'archived']);
    Route::post('/board/lists', [BoardController::class, 'storeList']);
    Route::post('/board/lists/reorder', [BoardController::class, 'reorderLists']);
    Route::patch('/board/lists/{list}', [BoardController::class, 'updateList']);
    Route::delete('/board/lists/{list}', [BoardController::class, 'destroyList']);
    Route::post('/board/lists/{list}/restore', [BoardController::class, 'restoreList']);
    Route::post('/board/labels', [BoardController::class, 'storeLabel']);
    Route::patch('/board/labels/{label}', [BoardController::class, 'updateLabel']);
    Route::delete('/board/labels/{label}', [BoardController::class, 'destroyLabel']);

    // O cartão por dentro. Tudo aninhado na tarefa: é ela que carrega a empresa.
    Route::get('/tasks/{task}/card', [TaskCardController::class, 'show']);
    Route::patch('/tasks/{task}/move', [TaskCardController::class, 'move']);
    Route::post('/tasks/{task}/archive', [TaskCardController::class, 'archive']);
    Route::post('/tasks/{task}/restore', [TaskCardController::class, 'restore']);
    Route::put('/tasks/{task}/labels', [TaskCardController::class, 'syncLabels']);
    Route::put('/tasks/{task}/members', [TaskCardController::class, 'syncMembers']);
    Route::post('/tasks/{task}/checklist', [TaskCardController::class, 'storeChecklistItem']);
    Route::patch('/tasks/{task}/checklist/{item}', [TaskCardController::class, 'updateChecklistItem']);
    Route::delete('/tasks/{task}/checklist/{item}', [TaskCardController::class, 'destroyChecklistItem']);
    Route::post('/tasks/{task}/comments', [TaskCardController::class, 'storeComment']);
    Route::delete('/tasks/{task}/comments/{comment}', [TaskCardController::class, 'destroyComment']);
    Route::post('/tasks/{task}/attachments', [TaskCardController::class, 'storeAttachment']);
    Route::get('/tasks/{task}/attachments/{attachment}', [TaskCardController::class, 'showAttachment']);
    Route::delete('/tasks/{task}/attachments/{attachment}', [TaskCardController::class, 'destroyAttachment']);

    // Google Agenda — conexão da conta + CRUD de eventos
    Route::get('/google/status', [GoogleAuthController::class, 'status']);
    Route::get('/google/connect', [GoogleAuthController::class, 'connect']);
    Route::delete('/google/disconnect', [GoogleAuthController::class, 'disconnect']);
    // Liga/desliga o agendamento de uma pessoa. Admin da empresa: mexe na agenda dos outros.
    Route::patch('/google/calendars/{id}/agenda', [GoogleAuthController::class, 'toggleAgenda'])
        ->middleware('admin');
    Route::get('/google/events', [EventController::class, 'index']);
    Route::post('/google/events', [EventController::class, 'store']);
    Route::patch('/google/events/{event}', [EventController::class, 'update']);
    Route::delete('/google/events/{event}', [EventController::class, 'destroy']);
    // Marcação manual de "aconteceu / não aconteceu" (o Meet não mede reunião por telefone ou presencial).
    Route::post('/google/events/{event}/attendance', [EventController::class, 'attendance']);
    // Gravações de reunião na ficha: lista por lead + streaming do vídeo (Drive por trás).
    Route::get('/conversations/{conversation}/recordings', [EventController::class, 'recordings']);
    Route::get('/meetings/{meeting}/recording', [EventController::class, 'recording']);

    Route::get('/quick-replies', [QuickReplyController::class, 'index']);
    Route::post('/quick-replies', [QuickReplyController::class, 'store']);
    Route::delete('/quick-replies/{quickReply}', [QuickReplyController::class, 'destroy']);

    // Contatos (sincronizados com o Google Contatos, 2 vias).
    Route::get('/contacts', [ContactController::class, 'index']);
    Route::post('/contacts', [ContactController::class, 'store']);
    Route::patch('/contacts/{contact}', [ContactController::class, 'update']);
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy']);

    // Listas de contatos (Google, planilha, leads do CRM) — é o que a campanha seleciona.
    Route::get('/contact-lists', [ContactListController::class, 'index']);
    Route::post('/contact-lists', [ContactListController::class, 'store']);
    Route::patch('/contact-lists/{contactList}', [ContactListController::class, 'update']);
    Route::delete('/contact-lists/{contactList}', [ContactListController::class, 'destroy']);
    Route::post('/contact-lists/{contactList}/sync', [ContactListController::class, 'sync']);
    Route::post('/contact-lists/import', [ContactListController::class, 'importar']);
    Route::post('/contact-lists/from-crm', [ContactListController::class, 'doCrm']);

    // Tabs personalizadas da lista de conversas (filtram por etiqueta/etapa).
    Route::get('/chat-tabs', [ChatTabController::class, 'index']);
    Route::post('/chat-tabs', [ChatTabController::class, 'store']);
    Route::patch('/chat-tabs/{chatTab}', [ChatTabController::class, 'update']);
    Route::delete('/chat-tabs/{chatTab}', [ChatTabController::class, 'destroy']);

    Route::get('/labels', [LabelController::class, 'index']);
    Route::post('/labels', [LabelController::class, 'store']);
    Route::delete('/labels/{label}', [LabelController::class, 'destroy']);

    // Memória / base de conhecimento
    Route::post('/conversations/{conversation}/memorize', [MemoryController::class, 'memorize']);
    Route::get('/memory', [MemoryController::class, 'index']);
    Route::post('/memory/chunks', [MemoryController::class, 'storeChunk']);
    Route::patch('/memory/chunks/{memoryChunk}', [MemoryController::class, 'updateChunk']);
    Route::delete('/memory/chunks/{memoryChunk}', [MemoryController::class, 'destroyChunk']);
    Route::put('/memory/style', [MemoryController::class, 'updateStyle']);
    Route::post('/memory/rules', [MemoryController::class, 'storeRule']);

    // Materiais (PDF etc.) que a IA envia quando julgar pertinente — no lugar da
    // automação por etapa, que exigia adivinhar o momento na configuração.
    Route::get('/materials', [MaterialController::class, 'index']);
    Route::post('/materials', [MaterialController::class, 'store']);
    Route::patch('/materials/{material}', [MaterialController::class, 'update']);
    Route::delete('/materials/{material}', [MaterialController::class, 'destroy']);
    Route::delete('/memory/rules/{styleRule}', [MemoryController::class, 'destroyRule']);

    // WhatsApp (Evolution) — somente admin (checado no controller)
    Route::get('/wpp/labels', [WhatsAppController::class, 'labels']);
    Route::post('/wpp/start', [WhatsAppController::class, 'start']);
    Route::post('/wpp/import-txt', [WhatsAppController::class, 'importTxt']);
    Route::get('/wpp/status', [WhatsAppController::class, 'status']);
    Route::get('/wpp/qr', [WhatsAppController::class, 'qr']);
    Route::get('/wpp/pair', [WhatsAppController::class, 'pair']);
    Route::post('/wpp/import', [WhatsAppController::class, 'import']);
    Route::post('/wpp/sync', [WhatsAppController::class, 'sync']);
    Route::get('/wpp/media/{message}', [WhatsAppController::class, 'media']);
    Route::get('/conversations/{conversation}/full', [WhatsAppController::class, 'loadFull']);
    Route::delete('/wpp/logout', [WhatsAppController::class, 'logout']);
    // Múltiplos números (contas): listar / criar (prospecção) / remover.
    Route::get('/wpp/accounts', [WhatsAppController::class, 'accounts']);
    Route::post('/wpp/accounts', [WhatsAppController::class, 'createAccount']);
    Route::post('/wpp/accounts/{account}/primary', [WhatsAppController::class, 'setPrimary']);
    Route::post('/wpp/accounts/{account}/active', [WhatsAppController::class, 'setActive']);
    Route::post('/wpp/accounts/{account}/avatars-only', [WhatsAppController::class, 'setAvatarsOnly']);
    Route::delete('/wpp/accounts/{account}', [WhatsAppController::class, 'destroyAccount']);

    // API oficial (Cloud API da Meta): conectar número, diagnosticar e listar templates.
    Route::post('/wpp/cloud/accounts', [WhatsAppCloudController::class, 'saveAccount']);
    Route::get('/wpp/cloud/accounts/{account}/status', [WhatsAppCloudController::class, 'status']);
    Route::get('/wpp/cloud/accounts/{account}/templates', [WhatsAppCloudController::class, 'templates']);
    // Template aprovado numa conversa (único envio permitido fora da janela de 24h).
    Route::get('/conversations/{conversation}/templates', [MessageController::class, 'templates']);
    Route::post('/conversations/{conversation}/template', [MessageController::class, 'sendTemplate']);

    // Campanhas de prospecção/disparo (mensagem da IA, anti-ban conservador) — só admin.
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::post('/campaigns', [CampaignController::class, 'store']);
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
    Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update']);
    Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy']);
    Route::post('/campaigns/{campaign}/contacts', [CampaignController::class, 'importContacts']);
    Route::post('/campaigns/{campaign}/contacts/from-lists', [CampaignController::class, 'importFromLists']);
});
