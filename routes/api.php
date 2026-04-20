<?php

use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;
use Illuminate\Support\Facades\Route;

//remove auth middleware for testing purposes, add it back in production

Route::post('/conversations', [ConversationController::class, 'store']);
Route::get('/conversations', [ConversationController::class, 'index']);
Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);

Route::post('/messages', [MessageController::class, 'store']);
Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
