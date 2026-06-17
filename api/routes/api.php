<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\QuickReplyController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WhatsAppController;
use Illuminate\Support\Facades\Route;

// --- Autenticação (Sanctum SPA cookie) ---
Route::post('/login', [AuthController::class, 'login']);

// --- Público: canal do cliente (portal de solicitações, sem login) ---
Route::post('/solicitacoes', [TaskController::class, 'store']);

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

    Route::get('/deals', [DealController::class, 'index']);
    Route::post('/deals', [DealController::class, 'store']);
    Route::patch('/deals/{deal}', [DealController::class, 'update']);

    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update']);

    Route::get('/quick-replies', [QuickReplyController::class, 'index']);
    Route::post('/quick-replies', [QuickReplyController::class, 'store']);
    Route::delete('/quick-replies/{quickReply}', [QuickReplyController::class, 'destroy']);

    // WhatsApp (Evolution) — somente admin (checado no controller)
    Route::get('/wpp/status', [WhatsAppController::class, 'status']);
    Route::get('/wpp/qr', [WhatsAppController::class, 'qr']);
    Route::delete('/wpp/logout', [WhatsAppController::class, 'logout']);
});
