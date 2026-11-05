<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

// The CSRF check (bootstrap/app.php): every write needs the token, whatever
// page it comes from, other *.developermajd.com pages included; signing in,
// signing up and password resets too. Only the page-close beacon doesn't.
//
// Laravel skips the check while testing, so these use the real middleware
// with that one switch turned off.

beforeEach(function () {
    $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
});

afterEach(function () {
    Redis::flushdb();
});

/** The X-XSRF-TOKEN header the SPA sends: the XSRF-TOKEN cookie as it reads it. */
function xsrfHeader(string $token): string
{
    $encrypter = app(Encrypter::class);

    return $encrypter->encrypt(CookieValuePrefix::create('XSRF-TOKEN', $encrypter->getKey()).$token, false);
}

/**
 * A request from a page on another *.developermajd.com subdomain (the
 * browser says "same-site"), or from another site altogether.
 */
function fromPage(string $site, string $method, string $uri, array $data = [], array $headers = []): TestResponse
{
    return test()->json($method, $uri, $data, ['Sec-Fetch-Site' => $site, ...$headers]);
}

dataset('other pages', ['another developermajd.com page' => 'same-site', 'another site' => 'cross-site']);

test('a write without the token is refused, from another developermajd.com page as from anywhere else', function (string $site) {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create(['created_by_user_id' => $user->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);

    $this->actingAs($user);
    fromPage($site, 'POST', '/api/messages', ['conversation_id' => $conversation->id, 'body' => 'forged'])->assertStatus(419);
    fromPage($site, 'PATCH', '/api/settings', ['read_receipts' => false])->assertStatus(419);
    // (/broadcasting/auth is left out by Laravel itself: it changes
    // nothing, and the signature it answers with is only of use to a page
    // allowed to read it, which CORS keeps to the app's own origin.)

    expect($conversation->messages()->count())->toBe(0);
})->with('other pages');

// Login CSRF: another site signing the visitor in to an account of its own.
test('signing in, signing up and password resets need the token too', function (string $site) {
    User::factory()->create(['email' => 'eve@example.com', 'password' => Hash::make('Secret-pass-1!')]);

    fromPage($site, 'POST', '/api/auth/login', ['email' => 'eve@example.com', 'password' => 'Secret-pass-1!'])->assertStatus(419);
    fromPage($site, 'POST', '/api/auth/register', ['name' => 'Mal', 'email' => 'mal@example.com', 'password' => 'Secret-pass-1!', 'password_confirmation' => 'Secret-pass-1!'])->assertStatus(419);
    fromPage($site, 'POST', '/api/auth/forgot-password', ['email' => 'eve@example.com'])->assertStatus(419);
    fromPage($site, 'POST', '/api/auth/reset-password', ['token' => 'x', 'email' => 'eve@example.com', 'password' => 'Secret-pass-2!', 'password_confirmation' => 'Secret-pass-2!'])->assertStatus(419);

    $this->assertGuest();
    expect(User::where('email', 'mal@example.com')->exists())->toBeFalse();
})->with('other pages');

test('the app’s own requests carry the token and go through', function () {
    User::factory()->create(['email' => 'eve@example.com', 'password' => Hash::make('Secret-pass-1!')]);
    $this->withSession(['_token' => 'the-token']);

    // The app is on rtm.developermajd.com, the API on api.rtm…: same site.
    fromPage('same-site', 'POST', '/api/auth/login', ['email' => 'eve@example.com', 'password' => 'Secret-pass-1!'], ['X-XSRF-TOKEN' => xsrfHeader('the-token')])
        ->assertOk();
});

test('a wrong token is refused', function () {
    User::factory()->create(['email' => 'eve@example.com', 'password' => Hash::make('Secret-pass-1!')]);
    $this->withSession(['_token' => 'the-token']);

    fromPage('same-site', 'POST', '/api/auth/login', ['email' => 'eve@example.com', 'password' => 'Secret-pass-1!'], ['X-XSRF-TOKEN' => xsrfHeader('another-token')])
        ->assertStatus(419);
});

// navigator.sendBeacon can't send headers; forged, it only makes someone
// look offline until their next heartbeat.
test('the page-close beacon needs no token', function () {
    $this->actingAs(User::factory()->create());

    fromPage('same-site', 'POST', '/api/presence/leave')->assertNoContent();
});
