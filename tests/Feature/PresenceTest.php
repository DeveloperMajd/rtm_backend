<?php

use App\Models\User;
use App\Services\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

afterEach(function () {
    Redis::flushdb();
});

test('a user is offline until they send a heartbeat', function () {
    $user = User::factory()->create();
    $service = app(PresenceService::class);

    expect($service->isOnline($user->id))->toBeFalse();

    $this->actingAs($user)->postJson('/api/presence/heartbeat')->assertNoContent();

    expect($service->isOnline($user->id))->toBeTrue();
});

test('a heartbeat updates last_seen_at', function () {
    $user = User::factory()->create(['last_seen_at' => null]);

    $this->actingAs($user)->postJson('/api/presence/heartbeat')->assertNoContent();

    expect($user->fresh()->last_seen_at)->not->toBeNull();
});

test('leaving clears presence immediately instead of waiting for the ttl', function () {
    $user = User::factory()->create();
    $service = app(PresenceService::class);

    $this->actingAs($user)->postJson('/api/presence/heartbeat')->assertNoContent();
    expect($service->isOnline($user->id))->toBeTrue();

    $this->actingAs($user)->postJson('/api/presence/leave')->assertNoContent();
    expect($service->isOnline($user->id))->toBeFalse();
});

test('unauthenticated requests cannot send a heartbeat', function () {
    $this->postJson('/api/presence/heartbeat')->assertUnauthorized();
});

test('onlineUserIds only returns the ids that currently have a presence key', function () {
    $online = User::factory()->create();
    $offline = User::factory()->create();
    $service = app(PresenceService::class);

    $service->heartbeat($online);

    expect($service->onlineUserIds([$online->id, $offline->id]))->toBe([$online->id]);
});

test('the contact directory search surfaces is_online per user', function () {
    $viewer = User::factory()->create();
    $online = User::factory()->create(['name' => 'Zephyr Online']);
    $offline = User::factory()->create(['name' => 'Zephyr Offline']);

    app(PresenceService::class)->heartbeat($online);

    $response = $this->actingAs($viewer)->getJson('/api/contacts/search?q=Zephyr');

    $response->assertOk();
    $data = collect($response->json('data'));
    expect($data->firstWhere('id', $online->id)['is_online'])->toBeTrue();
    expect($data->firstWhere('id', $offline->id)['is_online'])->toBeFalse();
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

    expect($service->isOnline($user->id))->toBeFalse();
    expect($service->onlineUserIds([$user->id]))->toBe([]);

    $service->heartbeat($user);
    expect($user->fresh()->last_seen_at)->not->toBeNull();

    $this->actingAs($user)->postJson('/api/presence/heartbeat')->assertNoContent();

    // shouldReceive() rebinds the container's 'redis' singleton, not just
    // the facade's cached instance — both must be undone or the file's
    // afterEach (Redis::flushdb()) hits the mock instead of a real Redis.
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
});
