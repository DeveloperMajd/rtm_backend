<?php

use App\Enums\PresenceStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Models\UserSettings;
use App\Services\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

afterEach(function () {
    Redis::flushdb();
});

/** A direct conversation between the two. */
function presenceDirect(User $one, User $other): Conversation
{
    $conversation = Conversation::factory()->create(['created_by_user_id' => $one->id]);
    foreach ([$one, $other] as $user) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'participant',
            'joined_at' => now(),
        ]);
    }

    return $conversation;
}

test('a user is offline until they send a heartbeat', function () {
    $user = User::factory()->create();
    $service = app(PresenceService::class);

    expect($service->statusOf($user->id))->toBe(PresenceStatus::Offline);

    $this->actingAs($user)->postJson('/api/presence/heartbeat')->assertNoContent();

    expect($service->statusOf($user->id))->toBe(PresenceStatus::Online);
});

test('a heartbeat updates last_seen_at', function () {
    $user = User::factory()->create(['last_seen_at' => null]);

    $this->actingAs($user)->postJson('/api/presence/heartbeat')->assertNoContent();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

test('an idle app keeps someone online, but away, until they use it again', function () {
    $user = User::factory()->create(['last_seen_at' => null]);
    $service = app(PresenceService::class);

    $this->actingAs($user)->postJson('/api/presence/heartbeat', ['state' => 'away'])->assertNoContent();

    expect($service->statusOf($user->id))->toBe(PresenceStatus::Away)
        ->and($service->statusOf($user->id)->isOnline())->toBeTrue()
        // Last seen goes by the app being open, idle or not.
        ->and($user->fresh()->last_seen_at)->not->toBeNull();

    $this->actingAs($user)->postJson('/api/presence/heartbeat', ['state' => 'active'])->assertNoContent();
    expect($service->statusOf($user->id))->toBe(PresenceStatus::Online);

    $this->actingAs($user)->postJson('/api/presence/heartbeat', ['state' => 'away'])->assertNoContent();
    // A client from before away presence sends no state: that's using the app.
    $this->actingAs($user)->postJson('/api/presence/heartbeat')->assertNoContent();
    expect($service->statusOf($user->id))->toBe(PresenceStatus::Online);
});

test('turns away a state it doesn’t know, and leaves presence as it was', function () {
    $user = User::factory()->create();
    $service = app(PresenceService::class);
    $service->heartbeat($user, away: true);

    $this->actingAs($user)->postJson('/api/presence/heartbeat', ['state' => 'busy'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('state');

    expect($service->statusOf($user->id))->toBe(PresenceStatus::Away);
});

// What a heartbeat stored before away presence, still live for a few
// seconds after the deploy.
test('a heartbeat from before away presence still reads as online', function () {
    $user = User::factory()->create();

    Redis::connection()->setex("presence:online:{$user->id}", 30, now()->toIso8601String());

    expect(app(PresenceService::class)->statusOf($user->id))->toBe(PresenceStatus::Online);
});

test('leaving clears presence immediately instead of waiting for the ttl', function () {
    $user = User::factory()->create();
    $service = app(PresenceService::class);

    $this->actingAs($user)->postJson('/api/presence/heartbeat', ['state' => 'away'])->assertNoContent();
    expect($service->statusOf($user->id))->toBe(PresenceStatus::Away);

    $this->actingAs($user)->postJson('/api/presence/leave')->assertNoContent();
    expect($service->statusOf($user->id))->toBe(PresenceStatus::Offline);
});

test('unauthenticated requests cannot send a heartbeat', function () {
    $this->postJson('/api/presence/heartbeat')->assertUnauthorized();
});

test('statusesOf gives everyone’s status in one go, keyed by user', function () {
    $online = User::factory()->create();
    $away = User::factory()->create();
    $offline = User::factory()->create();
    $service = app(PresenceService::class);

    $service->heartbeat($online);
    $service->heartbeat($away, away: true);

    expect($service->statusesOf([$online->id, $away->id, $offline->id]))->toBe([
        $online->id => PresenceStatus::Online,
        $away->id => PresenceStatus::Away,
        $offline->id => PresenceStatus::Offline,
    ])->and($service->statusesOf([]))->toBe([]);
});

test('the contact directory search surfaces is_online and presence_status per user', function () {
    $viewer = User::factory()->create();
    $online = User::factory()->create(['name' => 'Zephyr Online']);
    $away = User::factory()->create(['name' => 'Zephyr Away']);
    $offline = User::factory()->create(['name' => 'Zephyr Offline']);

    app(PresenceService::class)->heartbeat($online);
    app(PresenceService::class)->heartbeat($away, away: true);

    $response = $this->actingAs($viewer)->getJson('/api/contacts/search?q=Zephyr');

    $response->assertOk();
    $data = collect($response->json('data'));
    expect($data->firstWhere('id', $online->id))->toMatchArray(['is_online' => true, 'presence_status' => 'online'])
        ->and($data->firstWhere('id', $away->id))->toMatchArray(['is_online' => true, 'presence_status' => 'away'])
        ->and($data->firstWhere('id', $offline->id))->toMatchArray(['is_online' => false, 'presence_status' => 'offline']);
});

test('conversations, their members and contacts all say who’s away', function () {
    $viewer = User::factory()->create();
    $sam = User::factory()->create();
    $conversation = presenceDirect($viewer, $sam);
    Contact::create(['user_id' => $viewer->id, 'contact_user_id' => $sam->id]);

    app(PresenceService::class)->heartbeat($sam, away: true);

    $this->actingAs($viewer)->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.other_participant.is_online', true)
        ->assertJsonPath('data.0.other_participant.presence_status', 'away');

    $members = collect($this->actingAs($viewer)->getJson('/api/conversations')->json('data.0.participants'));
    expect($members->firstWhere('user_id', $sam->id))->toMatchArray(['is_online' => true, 'presence_status' => 'away'])
        ->and($members->firstWhere('user_id', $viewer->id))->toMatchArray(['is_online' => false, 'presence_status' => 'offline']);

    $participants = collect($this->actingAs($viewer)->getJson("/api/conversations/{$conversation->id}/participants")->assertOk()->json('data'));
    expect($participants->firstWhere('user_id', $sam->id))->toMatchArray(['is_online' => true, 'presence_status' => 'away']);

    $this->actingAs($viewer)->getJson('/api/contacts')
        ->assertOk()
        ->assertJsonPath('data.0.presence_status', 'away');
});

// Like being online, being away isn't what "last seen" hides (audit Q10).
test('shows someone as away even when they hide when they were last seen', function () {
    $viewer = User::factory()->create();
    $sam = User::factory()->create(['last_seen_at' => now()]);
    presenceDirect($viewer, $sam);
    UserSettings::for($sam)->fill(['last_seen_visibility' => 'nobody'])->save();

    app(PresenceService::class)->heartbeat($sam, away: true);

    $this->actingAs($viewer)->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.other_participant.presence_status', 'away')
        ->assertJsonPath('data.0.other_participant.last_seen_at', null);
});

test('presence degrades to offline instead of throwing when redis is unavailable', function () {
    // Regression test: a real production incident (Upstash free-tier
    // quota exhausted) made every Redis call throw, which took down
    // /api/conversations entirely since ConversationResource calls
    // PresenceService unconditionally. Presence is ephemeral/non-critical
    // and must fail soft instead.
    $user = User::factory()->create(['last_seen_at' => null]);

    Redis::shouldReceive('connection')->andThrow(new RedisException('AUTH failed while reconnecting'));

    $service = app(PresenceService::class);

    expect($service->statusOf($user->id))->toBe(PresenceStatus::Offline);
    expect($service->statusesOf([$user->id]))->toBe([$user->id => PresenceStatus::Offline]);

    $service->heartbeat($user, away: true);
    expect($user->fresh()->last_seen_at)->not->toBeNull();

    $this->actingAs($user)->postJson('/api/presence/heartbeat', ['state' => 'away'])->assertNoContent();

    // shouldReceive() rebinds the container's 'redis' singleton, not just
    // the facade's cached instance — both must be undone or the file's
    // afterEach (Redis::flushdb()) hits the mock instead of a real Redis.
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
});
