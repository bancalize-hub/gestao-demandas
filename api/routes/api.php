<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\QuickReplyController;
use App\Http\Controllers\Api\StageController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WhatsAppController;
use Illuminate\Support\Facades\Route;

// --- Autenticação (Sanctum SPA cookie) ---
Route::post('/login', [AuthController::class, 'login']);

// --- Público: canal do cliente (portal de solicitações, sem login) ---
Route::post('/solicitacoes', [TaskController::class, 'store']);

// --- Público: webhook do Evolution (protegido por token na query) ---
Route::post('/wpp/webhook', [WhatsAppController::class, 'webhook']);

// --- Protegido (auth:sanctum) ---
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Cadastro restrito: somente admin gerencia usuários.
    Route::get('/users', [AuthController::class, 'index']);
    Route::post('/users', [AuthController::class, 'store']);

    // CRM
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::post('/conversations/{conversation}/suggest-reply', [ConversationController::class, 'suggestReply']);

    Route::get('/stages', [StageController::class, 'index']);
    Route::post('/stages', [StageController::class, 'store']);
    Route::post('/stages/reorder', [StageController::class, 'reorder']);
    Route::patch('/stages/{stage}', [StageController::class, 'update']);
    Route::delete('/stages/{stage}', [StageController::class, 'destroy']);

    Route::get('/deals', [DealController::class, 'index']);
    Route::post('/deals', [DealController::class, 'store']);
    Route::patch('/deals/{deal}', [DealController::class, 'update']);
    Route::delete('/deals/{deal}', [DealController::class, 'destroy']);

    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);

    Route::get('/quick-replies', [QuickReplyController::class, 'index']);
    Route::post('/quick-replies', [QuickReplyController::class, 'store']);
    Route::delete('/quick-replies/{quickReply}', [QuickReplyController::class, 'destroy']);

    Route::get('/labels', [LabelController::class, 'index']);
    Route::post('/labels', [LabelController::class, 'store']);
    Route::delete('/labels/{label}', [LabelController::class, 'destroy']);

    // Memória / base de conhecimento
    Route::post('/conversations/{conversation}/memorize', [MemoryController::class, 'memorize']);
    Route::get('/memory', [MemoryController::class, 'index']);
    Route::patch('/memory/chunks/{memoryChunk}', [MemoryController::class, 'updateChunk']);
    Route::delete('/memory/chunks/{memoryChunk}', [MemoryController::class, 'destroyChunk']);
    Route::put('/memory/style', [MemoryController::class, 'updateStyle']);
    Route::post('/memory/rules', [MemoryController::class, 'storeRule']);
    Route::delete('/memory/rules/{styleRule}', [MemoryController::class, 'destroyRule']);

    // WhatsApp (Evolution) — somente admin (checado no controller)
    Route::get('/wpp/status', [WhatsAppController::class, 'status']);
    Route::get('/wpp/qr', [WhatsAppController::class, 'qr']);
    Route::get('/wpp/pair', [WhatsAppController::class, 'pair']);
    Route::post('/wpp/import', [WhatsAppController::class, 'import']);
    Route::post('/wpp/sync', [WhatsAppController::class, 'sync']);
    Route::get('/wpp/media/{message}', [WhatsAppController::class, 'media']);
    Route::get('/conversations/{conversation}/full', [WhatsAppController::class, 'loadFull']);
    Route::delete('/wpp/logout', [WhatsAppController::class, 'logout']);
});
