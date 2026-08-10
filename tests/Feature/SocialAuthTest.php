<?php

use App\Models\User;
use App\Models\UserAuthProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

it('redirects to the provider for a supported driver', function (string $provider) {
    Socialite::fake($provider);

    $response = $this->get("/api/auth/{$provider}/redirect");

    $response->assertRedirect("https://socialite.fake/{$provider}/authorize");
})->with(['google', 'facebook']);

it('returns 404 for an unsupported provider', function (string $action) {
    $this->get("/api/auth/twitter/{$action}")->assertNotFound();
})->with(['redirect', 'callback']);

it('creates a new user and links the provider identity on first login', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'avatar' => 'https://example.com/ada.jpg',
    ]));

    $response = $this->get('/api/auth/google/callback');

    $response->assertRedirect(config('services.frontend_url').'/conversations');

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->password)->toBeNull();
    expect($user->avatar_url)->toBe('https://example.com/ada.jpg');

    $authProvider = UserAuthProvider::sole();
    expect($authProvider->user_id)->toBe($user->id);
    expect($authProvider->provider)->toBe('google');
    expect($authProvider->provider_user_id)->toBe('google-123');

    $this->assertAuthenticatedAs($user);
});

it('reuses the same user on a repeat login from the same provider identity', function () {
    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', 'email' => 'ada@example.com']));

    $this->get('/api/auth/google/callback');
    $this->get('/api/auth/google/callback');

    expect(User::count())->toBe(1);
    expect(UserAuthProvider::count())->toBe(1);
});

it('links a new provider identity to an existing password account with a matching email', function () {
    $existingUser = User::factory()->create(['email' => 'ada@example.com']);
    $originalPasswordHash = $existingUser->password;

    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', 'email' => 'ada@example.com']));

    $this->get('/api/auth/google/callback');

    expect(User::count())->toBe(1);

    $authProvider = UserAuthProvider::sole();
    expect($authProvider->user_id)->toBe($existingUser->id);

    $existingUser->refresh();
    expect($existingUser->password)->toBe($originalPasswordHash);

    $this->assertAuthenticatedAs($existingUser);
});

it('redirects to the frontend login with an error when the provider callback fails', function () {
    Socialite::fake('google', function () {
        throw new RuntimeException('provider denied access');
    });

    $response = $this->get('/api/auth/google/callback');

    $response->assertRedirect(config('services.frontend_url').'/login?error=oauth_failed');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});
