<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

test('forgot-password sends a reset link for a registered email', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/auth/forgot-password', ['email' => 'ada@example.com'])
        ->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

test('forgot-password responds the same way for an email that is not registered', function () {
    Notification::fake();

    $registered = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

    $registered->assertOk();
    Notification::assertNothingSent();
});

test('the mailed reset link points at the frontend route, not a Laravel view', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/auth/forgot-password', ['email' => 'ada@example.com']);

    Notification::assertSentTo($user, function (ResetPassword $notification) use ($user) {
        $mail = $notification->toMail($user);

        expect($mail->actionUrl)->toStartWith(config('services.frontend_url'));
        expect($mail->actionUrl)->toContain('/reset-password?');
        expect($mail->actionUrl)->toContain('token=');
        expect($mail->actionUrl)->toContain(urlencode($user->email));

        return true;
    });
});

test('a user can reset their password with a valid token', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'OldStr0ng!']);
    $token = Password::createToken($user);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => 'ada@example.com',
        'password' => 'NewStr0ng!',
        'password_confirmation' => 'NewStr0ng!',
    ])->assertOk();

    expect(Hash::check('NewStr0ng!', $user->fresh()->password))->toBeTrue();

    $this->postJson('/api/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'NewStr0ng!',
    ])->assertOk();
});

test('resetting with an invalid token is rejected and leaves the password unchanged', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'OldStr0ng!']);

    $this->postJson('/api/auth/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'ada@example.com',
        'password' => 'NewStr0ng!',
        'password_confirmation' => 'NewStr0ng!',
    ])->assertStatus(422);

    expect(Hash::check('OldStr0ng!', $user->fresh()->password))->toBeTrue();
});

test('the new password from a reset must meet the strength rules', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = Password::createToken($user);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => 'ada@example.com',
        'password' => 'weak',
        'password_confirmation' => 'weak',
    ])->assertJsonValidationErrors('password');
});
