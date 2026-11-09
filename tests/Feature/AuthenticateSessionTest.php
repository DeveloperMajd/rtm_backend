<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

// sessionFingerprint() (tests/Pest.php) is what AuthenticateSession keeps in
// a session; it ends the session once that no longer matches the password.

/**
 * As a new request finds things: the signed-in account read afresh from the
 * database. In a test every request shares one app, which would otherwise
 * keep the account as the previous request left it (its old password, say).
 */
function nextRequest(): void
{
    Auth::forgetGuards();
}

test('a session signed in with a password the account no longer has is signed out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['password_hash_web' => sessionFingerprint('an earlier password hash')])
        ->getJson('/api/conversations')
        ->assertUnauthorized();
});

test('nor does such a session get a broadcast channel signed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['password_hash_web' => sessionFingerprint('an earlier password hash')])
        ->postJson('/broadcasting/auth', [
            'channel_name' => "private-App.Models.User.{$user->id}",
            'socket_id' => '1234.5678',
        ])
        ->assertUnauthorized();
});

test('a session signed in with the current password stays signed in', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['password_hash_web' => sessionFingerprint($user->password)])
        ->getJson('/api/conversations')
        ->assertOk();
});

test('an account with no password, made through Google, stays signed in from request to request', function () {
    $user = User::factory()->create(['password' => null]);

    // Fingerprinted as an empty string: hash_hmac() no longer takes null.
    expect($user->getAuthPassword())->toBe('');

    $this->actingAs($user)->getJson('/api/conversations')->assertOk();
    $this->getJson('/api/conversations')->assertOk();
});

test('changing your password keeps your own session and signs the others out', function () {
    $user = User::factory()->create(['password' => 'OldStr0ng!']);
    $signedInWithTheOldOne = sessionFingerprint($user->password);

    $this->actingAs($user)->patchJson('/api/profile/password', [
        'current_password' => 'OldStr0ng!',
        'password' => 'NewStr0ng!',
        'password_confirmation' => 'NewStr0ng!',
    ])->assertNoContent();

    // The session that changed it took the new password's fingerprint…
    $this->getJson('/api/profile')->assertOk();

    // …while one still signed in with the old password is over.
    $this->withSession(['password_hash_web' => $signedInWithTheOldOne])
        ->getJson('/api/profile')
        ->assertUnauthorized();
});

// One that has only signed in, and asked for nothing since, holds just what
// signing in left in the session. It mustn't miss the check by making its
// first request after the password has changed.
test('a session that has only just signed in is signed out by a password change elsewhere too', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'OldStr0ng!']);
    $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'OldStr0ng!'])->assertOk();

    // Changed on another device.
    $user->forceFill(['password' => 'NewStr0ng!'])->save();
    nextRequest();

    $this->getJson('/api/auth/me')->assertUnauthorized();
});

test('resetting the password signs out every session signed in with the old one', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'OldStr0ng!']);
    $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'OldStr0ng!'])->assertOk();
    $this->getJson('/api/auth/me')->assertOk();

    // From the emailed link, say in another browser.
    $this->postJson('/api/auth/reset-password', [
        'token' => Password::createToken($user),
        'email' => 'ada@example.com',
        'password' => 'NewStr0ng!',
        'password_confirmation' => 'NewStr0ng!',
    ])->assertOk();
    nextRequest();

    $this->getJson('/api/auth/me')->assertUnauthorized();
    nextRequest();
    // …and stays signed out.
    $this->getJson('/api/conversations')->assertUnauthorized();
});

test('signing in starts without a fingerprint left in the session from before', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    // Another account's, say, signed in on this browser before.
    $this->withSession(['password_hash_web' => sessionFingerprint('another account\'s password hash')])
        ->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk();

    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
});

// An account made through Google has no password, so signing in stores no
// fingerprint of its own: one left from another account must go.
test('a Google sign-in starts without another account\'s fingerprint, too', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-9',
        'name' => 'Bea',
        'email' => 'bea@example.com',
        'email_verified' => true,
    ]));

    $this->withSession(['password_hash_web' => sessionFingerprint('another account\'s password hash')])
        ->get('/api/auth/google/callback')
        ->assertRedirect();
    nextRequest();

    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.email', 'bea@example.com');
});
