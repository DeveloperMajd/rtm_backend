<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Conversation}
 */
function rateLimitedGroup(): array
{
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $group = Conversation::factory()->create([
        'created_by_user_id' => $admin->id,
        'type' => 'group',
        'title' => 'Limits',
    ]);

    foreach ([$admin, $member] as $user) {
        ConversationParticipant::create([
            'conversation_id' => $group->id,
            'user_id' => $user->id,
            'role' => $user->is($admin) ? 'admin' : 'participant',
            'joined_at' => now(),
        ]);
    }

    return [$admin, $group];
}

// Regression: every throttled route used to share one counter per user, so
// ordinary chatting (read receipts, typing) used up the 10-a-minute
// allowance of routes like deleting a group.
test('a burst on one route does not use up another route\'s limit', function () {
    [$admin, $group] = rateLimitedGroup();

    for ($i = 0; $i < 12; $i++) {
        $this->actingAs($admin)->postJson("/api/conversations/{$group->id}/read")->assertNoContent();
    }

    $this->actingAs($admin)->deleteJson("/api/conversations/{$group->id}")->assertNoContent();
});

test('each route still enforces its own limit', function () {
    [$admin, $group] = rateLimitedGroup();

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($admin)->postJson("/api/conversations/{$group->id}/read")->assertNoContent();
    }

    $this->actingAs($admin)->postJson("/api/conversations/{$group->id}/read")->assertTooManyRequests();
});

/**
 * A request as it reaches PHP in production: from nginx, with the visitor's
 * address in X-Forwarded-For (Caddy sets it, ignoring any a visitor sends).
 *
 * @return array<string, string>
 */
function fromVisitor(string $ip): array
{
    return ['REMOTE_ADDR' => '172.20.0.5', 'HTTP_X_FORWARDED_FOR' => $ip, 'HTTP_X_FORWARDED_PROTO' => 'https'];
}

test("the visitor's address and scheme are read from the proxy in front", function () {
    Route::get('/_test/client', fn (Request $request) => ['ip' => $request->ip(), 'secure' => $request->secure()]);

    $this->withServerVariables(fromVisitor('203.0.113.7'))
        ->getJson('/_test/client')
        ->assertExactJson(['ip' => '203.0.113.7', 'secure' => true]);
});

// Regression: every visitor used to look like nginx, so five failed
// sign-ins a minute, from anyone, locked every sign-in out.
test('each visitor has a sign-in allowance of their own', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables(fromVisitor('203.0.113.7'))
            ->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
    }
    $this->withServerVariables(fromVisitor('203.0.113.7'))
        ->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
        ->assertTooManyRequests();

    $this->withServerVariables(fromVisitor('198.51.100.9'))
        ->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk();
});

test('guessing one account stops after five tries from an address, without stopping its other accounts', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables(fromVisitor('203.0.113.7'))
            ->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
    }

    $this->withServerVariables(fromVisitor('203.0.113.7'))
        ->postJson('/api/auth/login', ['email' => 'ADA@example.com', 'password' => 'wrong'])
        ->assertTooManyRequests();
    $this->withServerVariables(fromVisitor('203.0.113.7'))
        ->postJson('/api/auth/login', ['email' => 'bob@example.com', 'password' => 'wrong'])
        ->assertUnauthorized();
});

test('one address stops after twenty tries across accounts', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->withServerVariables(fromVisitor('203.0.113.7'))
            ->postJson('/api/auth/login', ['email' => "someone{$i}@example.com", 'password' => 'wrong'])
            ->assertUnauthorized();
    }

    $this->withServerVariables(fromVisitor('203.0.113.7'))
        ->postJson('/api/auth/login', ['email' => 'someone20@example.com', 'password' => 'wrong'])
        ->assertTooManyRequests();
});

test('signing up is limited per visitor, not for everybody', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables(fromVisitor('203.0.113.7'))->postJson('/api/auth/register', [])->assertUnprocessable();
    }
    $this->withServerVariables(fromVisitor('203.0.113.7'))->postJson('/api/auth/register', [])->assertTooManyRequests();

    $this->withServerVariables(fromVisitor('198.51.100.9'))->postJson('/api/auth/register', [])->assertUnprocessable();
});
