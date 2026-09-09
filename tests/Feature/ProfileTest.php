<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.avatars' => 'public']);
    Storage::fake('public');
});

test('profile endpoints require authentication', function () {
    $this->getJson('/api/profile')->assertUnauthorized();
    $this->patchJson('/api/profile', ['name' => 'X'])->assertUnauthorized();
    $this->postJson('/api/profile/avatar', [])->assertUnauthorized();
    $this->deleteJson('/api/profile/avatar')->assertUnauthorized();
});

test('a user can read their own profile', function () {
    $user = User::factory()->create(['name' => 'Ada', 'bio' => 'Hello']);

    $this->actingAs($user)
        ->getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', 'Ada')
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.bio', 'Hello')
        ->assertJsonPath('data.avatar_url', null);
});

test('a user can update their name and bio', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/api/profile', ['name' => 'New Name', 'bio' => 'New bio'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.bio', 'New bio');

    expect($user->fresh()->name)->toBe('New Name');
    expect($user->fresh()->bio)->toBe('New bio');
});

test('bio can be cleared and is length limited', function () {
    $user = User::factory()->create(['bio' => 'something']);

    $this->actingAs($user)
        ->patchJson('/api/profile', ['name' => $user->name, 'bio' => null])
        ->assertOk()
        ->assertJsonPath('data.bio', null);

    $this->actingAs($user)
        ->patchJson('/api/profile', ['name' => $user->name, 'bio' => str_repeat('a', 501)])
        ->assertJsonValidationErrors('bio');

    $this->actingAs($user)
        ->patchJson('/api/profile', ['bio' => 'x'])
        ->assertJsonValidationErrors('name');
});

test('a user can upload an avatar and its file is stored', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('me.png', 256, 256),
    ]);

    $response->assertOk();
    $user->refresh();

    expect($user->avatar_path)->not->toBeNull();
    expect($user->avatar_url)->toContain($user->avatar_path);
    Storage::disk('public')->assertExists($user->avatar_path);
    expect($response->json('data.avatar_url'))->toBe($user->avatar_url);
});

test('uploading a new avatar deletes the previous file', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('first.png', 128, 128),
    ])->assertOk();
    $first = $user->fresh()->avatar_path;

    $this->actingAs($user)->postJson('/api/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('second.png', 128, 128),
    ])->assertOk();
    $second = $user->fresh()->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('non-images and oversize files are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/profile/avatar', [
        'avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
    ])->assertJsonValidationErrors('avatar');

    $this->actingAs($user)->postJson('/api/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('huge.jpg')->size(6 * 1024),
    ])->assertJsonValidationErrors('avatar');
});

test('a user can remove their avatar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/profile/avatar', [
        'avatar' => UploadedFile::fake()->image('me.png', 128, 128),
    ])->assertOk();
    $path = $user->fresh()->avatar_path;

    $this->actingAs($user)
        ->deleteJson('/api/profile/avatar')
        ->assertOk()
        ->assertJsonPath('data.avatar_url', null);

    $user->refresh();
    expect($user->avatar_path)->toBeNull();
    expect($user->avatar_url)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('me returns the enriched profile shape', function () {
    $user = User::factory()->create(['bio' => 'about me']);

    $this->actingAs($user)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.bio', 'about me');
});
