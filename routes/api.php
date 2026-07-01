<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationParticipantController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->controller(AuthController::class)->middleware('web')->group(function () {
    Route::post('/register', 'register')->middleware('throttle:5,1');
    Route::post('/login', 'login')->middleware('throttle:5,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', 'logout');
        Route::get('/me', 'me');
    });
});

Route::middleware(['web', 'auth:sanctum'])->group(function () {
    Route::prefix('conversations')->controller(ConversationController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('throttle:10,1');
        Route::get('/{conversation}', 'show');
        Route::post('/{conversation}/typing', 'typing')->middleware('throttle:30,1');
    });

    Route::controller(MessageController::class)->group(function () {
        Route::post('/messages', 'store')->middleware('throttle:30,1');
        Route::get('/conversations/{conversation}/messages', 'index');
    });

    Route::get('/users', [UserController::class, 'index']);

    Route::prefix('conversations/{conversation}/participants')->controller(ConversationParticipantController::class)->group(function () {
        Route::get('/', 'index');
        Route::delete('/{user}', 'destroy');
    });
});
