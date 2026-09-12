<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('registering with a strong password succeeds', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Str0ng!Pass',
    ]);

    $response->assertCreated();
    expect(User::where('email', 'ada@example.com')->exists())->toBeTrue();
});

test('registering requires the password confirmation to match', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Different1!',
    ])->assertJsonValidationErrors('password');
});

test('a password shorter than 8 characters is rejected', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Sh0rt!',
        'password_confirmation' => 'Sh0rt!',
    ])->assertJsonValidationErrors('password');
});

test('a password without an uppercase letter is rejected', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'lowercase1!',
        'password_confirmation' => 'lowercase1!',
    ])->assertJsonValidationErrors('password');
});

test('a password without a number is rejected', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'NoNumbers!',
        'password_confirmation' => 'NoNumbers!',
    ])->assertJsonValidationErrors('password');
});

test('a password without a symbol is rejected', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'NoSymbols1',
        'password_confirmation' => 'NoSymbols1',
    ])->assertJsonValidationErrors('password');
});

test('registering with an already-used email is rejected', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'taken@example.com',
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Str0ng!Pass',
    ])->assertJsonValidationErrors('email');
});

test('a registered user can log in and then log out', function () {
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'Str0ng!Pass']);

    $this->postJson('/api/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Pass',
    ])->assertOk();

    $this->postJson('/api/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'wrong-password',
    ])->assertUnauthorized();
});
