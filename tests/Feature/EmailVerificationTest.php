<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

// The API half of an emailed link: what the frontend's /verify-email page
// posts back.
function verificationLink(User $user, ?string $hash = null, int $minutes = 60): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes($minutes),
        ['id' => $user->id, 'hash' => $hash ?? sha1($user->email)],
        absolute: false,
    );
}

/** @return array<string, string> the query of the link in the email $user was sent */
function emailedLink(User $user): array
{
    $query = [];

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$query): bool {
        $link = $notification->toMail($user)->actionUrl;
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return str_starts_with($link, config('services.frontend_url').'/verify-email?');
    });

    return $query;
}

test('signing up sends a link to confirm the address, which goes to the frontend', function () {
    Notification::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Str0ng!Pass',
    ])->assertCreated()
        ->assertJsonStructure(['data' => ['email_verified_at']])
        ->assertJsonPath('data.email_verified_at', null);

    $link = emailedLink(User::where('email', 'ada@example.com')->sole());

    expect($link['hash'])->toBe(sha1('ada@example.com'));
    expect($link)->toHaveKeys(['id', 'expires', 'signature']);
});

test('signing up still works when the email cannot go out', function () {
    Event::listen(Registered::class, fn () => throw new RuntimeException('The mail service is down.'));

    $this->postJson('/api/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Str0ng!Pass',
    ])->assertCreated();

    $this->assertAuthenticated();
});

test('until the address is confirmed the account can only see who it is, sign out and ask for a link', function () {
    $user = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($user)->getJson('/api/conversations')
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified.');
    $this->getJson('/api/profile')->assertForbidden();
    $this->postJson('/broadcasting/auth', [
        'channel_name' => "private-App.Models.User.{$user->id}",
        'socket_id' => '1234.5678',
    ])->assertForbidden();

    $this->getJson('/api/auth/me')->assertOk()
        ->assertJsonStructure(['data' => ['email_verified_at']])
        ->assertJsonPath('data.email_verified_at', null);
    $this->postJson('/api/auth/logout')->assertNoContent();
});

test('the emailed link, posted back while signed in, confirms the address and opens the app', function () {
    Notification::fake();
    Event::fake([Verified::class]);
    $user = User::factory()->create(['email_verified_at' => null]);
    $user->sendEmailVerificationNotification();
    $link = emailedLink($user);

    $this->actingAs($user)
        ->postJson("/api/auth/email/verify/{$link['id']}/{$link['hash']}?expires={$link['expires']}&signature={$link['signature']}")
        ->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
    $this->getJson('/api/conversations')->assertOk();
});

// Opening the email isn't enough on its own: someone who signed up with
// another person's address would otherwise have that person verify it.
test('the link does nothing for anyone signed in as another account', function () {
    $user = User::factory()->create(['email_verified_at' => null]);
    $someoneElse = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($someoneElse)->postJson(verificationLink($user))->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    expect($someoneElse->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('nor for someone signed out', function () {
    $user = User::factory()->create(['email_verified_at' => null]);

    $this->postJson(verificationLink($user))->assertUnauthorized();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a link that was tampered with, has run out or names another address is refused', function (string $case) {
    $user = User::factory()->create(['email' => 'ada@example.com', 'email_verified_at' => null]);

    $link = match ($case) {
        'tampered with' => verificationLink($user).'0',
        'run out' => verificationLink($user, minutes: -1),
        'another address' => verificationLink($user, hash: sha1('someone@example.com')),
    };

    $this->actingAs($user)->postJson($link)->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
})->with(['tampered with', 'run out', 'another address']);

test('another link can be asked for, a few times in ten minutes', function () {
    Notification::fake();
    $user = User::factory()->create(['email_verified_at' => null]);

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($user)->postJson('/api/auth/email/verification-notification')->assertAccepted();
    }
    $this->postJson('/api/auth/email/verification-notification')->assertTooManyRequests();

    Notification::assertSentToTimes($user, VerifyEmail::class, 3);
});

test('an account already confirmed is not sent another link', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/auth/email/verification-notification')->assertNoContent();

    Notification::assertNothingSent();
});
