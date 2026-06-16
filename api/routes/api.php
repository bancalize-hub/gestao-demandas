<?php

use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/conversations', [ConversationController::class, 'index']);
Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
Route::post('/conversations/{conversation}/suggest-reply', [ConversationController::class, 'suggestReply']);

Route::get('/deals', [DealController::class, 'index']);
Route::patch('/deals/{deal}', [DealController::class, 'update']);

Route::get('/tasks', [TaskController::class, 'index']);
Route::post('/tasks', [TaskController::class, 'store']);
Route::patch('/tasks/{task}', [TaskController::class, 'update']);
