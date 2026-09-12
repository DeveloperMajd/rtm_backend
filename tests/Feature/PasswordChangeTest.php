<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('changing a password requires authentication', function () {
    $this->patchJson('/api/profile/password', [
        'current_password' => 'whatever',
        'password' => 'NewStr0ng!',
        'password_confirmation' => 'NewStr0ng!',
    ])->assertUnauthorized();
});

test('a user can change their password given the correct current password', function () {
    $user = User::factory()->create(['password' => 'OldStr0ng!']);

    $this->actingAs($user)->patchJson('/api/profile/password', [
        'current_password' => 'OldStr0ng!',
        'password' => 'NewStr0ng!',
        'password_confirmation' => 'NewStr0ng!',
    ])->assertNoContent();

    expect(Hash::check('NewStr0ng!', $user->fresh()->password))->toBeTrue();
});

test('changing a password fails with the wrong current password', function () {
    $user = User::factory()->create(['password' => 'OldStr0ng!']);

    $this->actingAs($user)->patchJson('/api/profile/password', [
        'current_password' => 'NotTheRightOne!',
        'password' => 'NewStr0ng!',
        'password_confirmation' => 'NewStr0ng!',
    ])->assertJsonValidationErrors('current_password');

    expect(Hash::check('OldStr0ng!', $user->fresh()->password))->toBeTrue();
});

test('the new password must meet the strength rules', function () {
    $user = User::factory()->create(['password' => 'OldStr0ng!']);

    $this->actingAs($user)->patchJson('/api/profile/password', [
        'current_password' => 'OldStr0ng!',
        'password' => 'weak',
        'password_confirmation' => 'weak',
    ])->assertJsonValidationErrors('password');
});
