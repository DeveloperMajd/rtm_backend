<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationParticipantController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageReactionController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->middleware('web')->group(function () {
    Route::controller(AuthController::class)->group(function () {
        Route::post('/register', 'register')->middleware('throttle:5,1');
        Route::post('/login', 'login')->middleware('throttle:5,1');
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', 'logout');
            Route::get('/me', 'me');
        });
    });

    Route::controller(SocialAuthController::class)->where(['provider' => 'google|facebook'])->group(function () {
        Route::get('/{provider}/redirect', 'redirect')->middleware('throttle:10,1');
        Route::get('/{provider}/callback', 'callback')->middleware('throttle:10,1');
    });
});

Route::middleware(['web', 'auth:sanctum'])->group(function () {
    Route::prefix('conversations')->controller(ConversationController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('throttle:10,1');
        Route::get('/{conversation}', 'show');
        Route::post('/{conversation}/typing', 'typing')->middleware('throttle:30,1');
        Route::post('/{conversation}/read', 'markAsRead')->middleware('throttle:30,1');
    });

    Route::controller(MessageController::class)->group(function () {
        Route::post('/messages', 'store')->middleware('throttle:30,1');
        Route::get('/messages/search', 'search')->middleware('throttle:30,1');
        Route::patch('/messages/{message}', 'update')->middleware('throttle:30,1');
        Route::delete('/messages/{message}', 'destroy')->middleware('throttle:30,1');
        Route::get('/conversations/{conversation}/messages', 'index');
    });

    Route::controller(AttachmentController::class)->group(function () {
        Route::post('/attachments', 'store')->middleware('throttle:30,1');
        Route::get('/attachments/{attachment}', 'show');
    });

    Route::prefix('messages/{message}/reactions')->controller(MessageReactionController::class)->group(function () {
        Route::post('/', 'store')->middleware('throttle:60,1');
        Route::delete('/{reaction}', 'destroy')->middleware('throttle:60,1');
    });

    Route::get('/users', [UserController::class, 'index']);

    Route::controller(PresenceController::class)->prefix('presence')->group(function () {
        Route::post('/heartbeat', 'heartbeat')->middleware('throttle:20,1');
        Route::post('/leave', 'leave');
    });

    Route::prefix('conversations/{conversation}/participants')->controller(ConversationParticipantController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('throttle:20,1');
        Route::delete('/{user}', 'destroy');
        Route::delete('/{user}/kick', 'kick')->middleware('throttle:20,1');
    });
});
