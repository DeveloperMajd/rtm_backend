<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// sessionFingerprint() (tests/Pest.php) is what AuthenticateSession keeps in
// a session; it ends the session once that no longer matches the password.

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

test('signing in starts without a fingerprint left in the session from before', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    // Another account's, say, signed in on this browser before.
    $this->withSession(['password_hash_web' => sessionFingerprint('another account\'s password hash')])
        ->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk();

    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
});
