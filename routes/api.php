<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationParticipantController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageReactionController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->middleware('web')->group(function () {
    Route::controller(AuthController::class)->group(function () {
        Route::post('/register', 'register')->middleware('throttle:5,1,auth.register.');
        Route::post('/login', 'login')->middleware('throttle:5,1,auth.login.');
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', 'logout');
            Route::get('/me', 'me');
        });
    });

    Route::controller(SocialAuthController::class)->where(['provider' => 'google'])->group(function () {
        Route::get('/{provider}/redirect', 'redirect')->middleware('throttle:10,1,auth.redirect.');
        Route::get('/{provider}/callback', 'callback')->middleware('throttle:10,1,auth.callback.');
    });

    Route::controller(PasswordResetController::class)->group(function () {
        Route::post('/forgot-password', 'forgotPassword')->middleware('throttle:5,1,auth.forgotPassword.');
        Route::post('/reset-password', 'reset')->middleware('throttle:5,1,auth.reset.');
    });
});

// Every limit below names its own bucket (the third throttle argument).
// Without one, Laravel keys an unnamed limit on the user alone, so every
// throttled route shared a single counter: a burst of read receipts and
// typing pings used up the 10-a-minute allowance for creating or deleting
// a group. Each route now counts only its own requests.
Route::middleware(['web', 'auth:sanctum'])->group(function () {
    Route::prefix('conversations')->controller(ConversationController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('throttle:10,1,conversations.store.');
        Route::get('/{conversation}', 'show');
        Route::patch('/{conversation}', 'update')->middleware('throttle:20,1,conversations.update.');
        Route::delete('/{conversation}', 'destroy')->middleware('throttle:10,1,conversations.destroy.');
        Route::post('/{conversation}/typing', 'typing')->middleware('throttle:30,1,conversations.typing.');
        Route::post('/{conversation}/read', 'markAsRead')->middleware('throttle:30,1,conversations.markAsRead.');
    });

    Route::controller(MessageController::class)->group(function () {
        Route::post('/messages', 'store')->middleware('throttle:30,1,messages.store.');
        Route::get('/messages/search', 'search')->middleware('throttle:30,1,messages.search.');
        Route::patch('/messages/{message}', 'update')->middleware('throttle:30,1,messages.update.');
        Route::delete('/messages/{message}', 'destroy')->middleware('throttle:30,1,messages.destroy.');
        Route::get('/conversations/{conversation}/messages', 'index');
    });

    Route::controller(AttachmentController::class)->group(function () {
        Route::post('/attachments', 'store')->middleware('throttle:30,1,attachments.store.');
        Route::get('/attachments/{attachment}', 'show');
    });

    Route::prefix('messages/{message}/reactions')->controller(MessageReactionController::class)->group(function () {
        Route::post('/', 'store')->middleware('throttle:60,1,reactions.store.');
        Route::delete('/{reaction}', 'destroy')->middleware('throttle:60,1,reactions.destroy.');
    });

    Route::prefix('contacts')->controller(ContactController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/search', 'search')->middleware('throttle:20,1,contacts.search.');
        Route::post('/', 'store')->middleware('throttle:20,1,contacts.store.');
        Route::delete('/{user}', 'destroy');
    });

    Route::prefix('profile')->controller(ProfileController::class)->group(function () {
        Route::get('/', 'show');
        Route::patch('/', 'update');
        Route::patch('/password', 'updatePassword')->middleware('throttle:5,1,profile.updatePassword.');
        Route::post('/avatar', 'updateAvatar')->middleware('throttle:10,1,profile.updateAvatar.');
        Route::delete('/avatar', 'destroyAvatar');
    });

    Route::controller(PresenceController::class)->prefix('presence')->group(function () {
        Route::post('/heartbeat', 'heartbeat')->middleware('throttle:20,1,presence.heartbeat.');
        Route::post('/leave', 'leave');
    });

    Route::prefix('conversations/{conversation}/participants')->controller(ConversationParticipantController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('throttle:20,1,participants.store.');
        Route::patch('/{user}', 'update')->middleware('throttle:20,1,participants.update.');
        Route::delete('/{user}', 'destroy');
        Route::delete('/{user}/kick', 'kick')->middleware('throttle:20,1,participants.kick.');
    });
});
