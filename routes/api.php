<?php

use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationParticipantController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

//remove auth middleware for testing purposes, add it back in production

Route::post('/conversations', [ConversationController::class, 'store']);
Route::get('/conversations', [ConversationController::class, 'index']);
Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);

Route::post('/messages', [MessageController::class, 'store']);
Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);

Route::get('/users', [UserController::class, 'index']);

Route::get('/conversations/{conversation}/participants', [ConversationParticipantController::class, 'index']);
Route::delete('/conversations/{conversation}/participants/{user}', [ConversationParticipantController::class, 'destroy']);
