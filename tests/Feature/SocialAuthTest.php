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
})->with(['google']);

it('returns 404 for an unsupported provider', function (string $action) {
    $this->get("/api/auth/facebook/{$action}")->assertNotFound();
})->with(['redirect', 'callback']);

it('creates a new user and links the provider identity on first login', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'email_verified' => true,
        'avatar' => 'https://example.com/ada.jpg',
    ]));

    $response = $this->get('/api/auth/google/callback');

    $response->assertRedirect(config('services.frontend_url').'/conversations');

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    expect($user->password)->toBeNull();
    expect($user->avatar_url)->toBe('https://example.com/ada.jpg');
    expect($user->email_verified_at)->not->toBeNull();

    $authProvider = UserAuthProvider::sole();
    expect($authProvider->user_id)->toBe($user->id);
    expect($authProvider->provider)->toBe('google');
    expect($authProvider->provider_user_id)->toBe('google-123');

    $this->assertAuthenticatedAs($user);
});

it('reuses the same user on a repeat login from the same provider identity', function () {
    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', 'email' => 'ada@example.com', 'email_verified' => true]));

    $this->get('/api/auth/google/callback');
    $this->get('/api/auth/google/callback');

    expect(User::count())->toBe(1);
    expect(UserAuthProvider::count())->toBe(1);
});

it('signs a linked identity in by its provider id alone, whatever the provider says about the email', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->authProviders()->create(['provider' => 'google', 'provider_user_id' => 'google-123']);

    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', 'email' => 'ada@example.com']));

    $this->get('/api/auth/google/callback')->assertRedirect(config('services.frontend_url').'/conversations');

    $this->assertAuthenticatedAs($user);
});

// An older version linked identities without confirming the address.
it('confirms and claims an account linked earlier whose email was never confirmed', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'email_verified_at' => null]);
    $user->authProviders()->create(['provider' => 'google', 'provider_user_id' => 'google-123']);
    $rememberToken = $user->remember_token;

    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', 'email' => 'ADA@example.com', 'email_verified' => true]));

    $this->get('/api/auth/google/callback')->assertRedirect(config('services.frontend_url').'/conversations');

    $user->refresh();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->password)->toBeNull();
    expect($user->remember_token)->not->toBe($rememberToken);
    $this->assertAuthenticatedAs($user);
});

it('leaves such an account unconfirmed when Google vouches for another address, or for none', function (array $google) {
    $user = User::factory()->create(['email' => 'ada@example.com', 'email_verified_at' => null]);
    $user->authProviders()->create(['provider' => 'google', 'provider_user_id' => 'google-123']);
    $passwordHash = $user->password;

    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', ...$google]));

    $this->get('/api/auth/google/callback')->assertRedirect(config('services.frontend_url').'/conversations');

    $user->refresh();
    expect($user->email_verified_at)->toBeNull();
    expect($user->password)->toBe($passwordHash);
    $this->assertAuthenticatedAs($user);
})->with([
    'another address' => [['email' => 'someone@example.com', 'email_verified' => true]],
    'not verified' => [['email' => 'ada@example.com', 'email_verified' => false]],
]);

it('claims an unverified password account with a matching email, and the password goes', function () {
    $squatted = User::factory()->create(['email' => 'ada@example.com', 'email_verified_at' => null]);
    $rememberToken = $squatted->remember_token;

    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', 'email' => 'ada@example.com', 'email_verified' => true]));

    $this->get('/api/auth/google/callback')->assertRedirect(config('services.frontend_url').'/conversations');

    expect(User::count())->toBe(1);
    expect(UserAuthProvider::sole()->user_id)->toBe($squatted->id);

    // Nobody had proved they own the address, so anyone could have chosen
    // that password; Google has just proved it, so the password goes.
    $squatted->refresh();
    expect($squatted->password)->toBeNull();
    expect($squatted->remember_token)->not->toBe($rememberToken);
    expect($squatted->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($squatted);
});

it('no longer signs in with the password an unverified account had once Google claims it', function () {
    User::factory()->create(['email' => 'ada@example.com', 'email_verified_at' => null]);

    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', 'email' => 'ada@example.com', 'email_verified' => true]));

    $this->get('/api/auth/google/callback');

    $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])->assertUnauthorized();
});

it('signs out the sessions an unverified account had once Google claims it', function () {
    $squatted = User::factory()->create(['email' => 'ada@example.com', 'email_verified_at' => null]);
    // What a session signed in with the old password carries.
    $squattersFingerprint = sessionFingerprint($squatted->password);

    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', 'email' => 'ada@example.com', 'email_verified' => true]));

    $this->get('/api/auth/google/callback');

    // Whoever just claimed it stays signed in…
    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $squatted->id);

    // …while a session signed in with the old password is over.
    $this->withSession(['password_hash_web' => $squattersFingerprint])
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

it('does not disturb an already-verified account when linking a provider', function () {
    $verifiedAt = now()->subDays(3)->startOfSecond();
    $existingUser = User::factory()->create(['email' => 'ada@example.com', 'email_verified_at' => $verifiedAt]);
    $passwordHash = $existingUser->password;

    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', 'email' => 'ada@example.com', 'email_verified' => true]));

    $this->get('/api/auth/google/callback');

    $existingUser->refresh();
    expect($existingUser->email_verified_at->equalTo($verifiedAt))->toBeTrue();
    expect($existingUser->password)->toBe($passwordHash);
    expect(UserAuthProvider::sole()->user_id)->toBe($existingUser->id);
});

it('refuses a sign-in by email the provider has not verified', function (array $claim) {
    $existingUser = User::factory()->create(['email' => 'ada@example.com', 'email_verified_at' => null]);
    $passwordHash = $existingUser->password;

    Socialite::fake('google', SocialiteUser::fake(['id' => 'google-123', 'email' => 'ada@example.com', ...$claim]));

    $this->get('/api/auth/google/callback')
        ->assertRedirect(config('services.frontend_url').'/login?error=oauth_failed');

    $this->assertGuest();
    expect(User::count())->toBe(1);
    expect(UserAuthProvider::count())->toBe(0);
    expect($existingUser->fresh()->password)->toBe($passwordHash);
})->with([
    'not verified' => [['email_verified' => false]],
    'not said' => [[]],
]);

it('redirects to the frontend login with an error when the provider callback fails', function () {
    Socialite::fake('google', function () {
        throw new RuntimeException('provider denied access');
    });

    $response = $this->get('/api/auth/google/callback');

    $response->assertRedirect(config('services.frontend_url').'/login?error=oauth_failed');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});
