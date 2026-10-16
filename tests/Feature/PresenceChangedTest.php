<?php

use App\Enums\PresenceStatus;
use App\Events\PresenceChanged;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Services\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([PresenceChanged::class]);
});

afterEach(function () {
    Redis::flushdb();
});

/** A conversation the given people are current members of. */
function sharedConversation(User ...$members): Conversation
{
    $conversation = Conversation::factory()->create(['created_by_user_id' => $members[0]->id]);
    foreach ($members as $user) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'participant',
            'joined_at' => now(),
        ]);
    }

    return $conversation;
}

/** @return array<int, PresenceChanged> */
function presenceChanges(): array
{
    return Event::dispatched(PresenceChanged::class)->map(fn (array $call) => $call[0])->all();
}

test('coming online tells everyone who sees them, and nobody else', function () {
    [$alice, $bob, $carol, $dave, $erin] = User::factory()->count(5)->create();
    sharedConversation($alice, $bob);
    Contact::create(['user_id' => $carol->id, 'contact_user_id' => $alice->id]);
    // Erin left the group they share: she doesn't see Alice any more.
    $group = sharedConversation($alice, $erin);
    $group->participants()->where('user_id', $erin->id)->update(['left_at' => now()]);

    $this->actingAs($alice)->postJson('/api/presence/heartbeat')->assertNoContent();

    Event::assertDispatchedTimes(PresenceChanged::class, 1);
    $event = presenceChanges()[0];
    expect($event->userId)->toBe($alice->id)
        ->and($event->status)->toBe(PresenceStatus::Online)
        ->and($event->recipientIds)->toEqualCanonicalizing([$bob->id, $carol->id])
        ->and(collect($event->broadcastOn())->pluck('name')->all())->toEqualCanonicalizing([
            "private-App.Models.User.{$bob->id}",
            "private-App.Models.User.{$carol->id}",
        ])
        ->and($event->broadcastWith())->toBe([
            'user_id' => $alice->id,
            'presence_status' => 'online',
            'is_online' => true,
        ]);
});

test('only a beat that changes the status tells anyone', function () {
    [$alice, $bob] = User::factory()->count(2)->create();
    sharedConversation($alice, $bob);

    $this->actingAs($alice)->postJson('/api/presence/heartbeat')->assertNoContent();
    $this->actingAs($alice)->postJson('/api/presence/heartbeat')->assertNoContent();
    $this->actingAs($alice)->postJson('/api/presence/heartbeat', ['state' => 'away'])->assertNoContent();
    $this->actingAs($alice)->postJson('/api/presence/heartbeat', ['state' => 'away'])->assertNoContent();
    $this->actingAs($alice)->postJson('/api/presence/heartbeat', ['state' => 'active'])->assertNoContent();

    expect(collect(presenceChanges())->map(fn (PresenceChanged $event) => $event->status)->all())
        ->toBe([PresenceStatus::Online, PresenceStatus::Away, PresenceStatus::Online]);
});

test('leaving tells them at once, and only the first time', function () {
    [$alice, $bob] = User::factory()->count(2)->create();
    sharedConversation($alice, $bob);
    app(PresenceService::class)->heartbeat($alice);

    $this->actingAs($alice)->postJson('/api/presence/leave')->assertNoContent();
    $this->actingAs($alice)->postJson('/api/presence/leave')->assertNoContent();

    expect(collect(presenceChanges())->map(fn (PresenceChanged $event) => $event->status)->all())
        ->toBe([PresenceStatus::Online, PresenceStatus::Offline]);
});

test('a key that runs out tells them they’re offline, unless a beat came in since', function () {
    [$alice, $bob] = User::factory()->count(2)->create();
    sharedConversation($alice, $bob);
    $service = app(PresenceService::class);
    $key = config('database.redis.options.prefix')."presence:online:{$alice->id}";

    // What presence:watch hands over once Redis has expired the key.
    $service->expired($key);
    expect(presenceChanges())->toHaveCount(1)
        ->and(presenceChanges()[0]->status)->toBe(PresenceStatus::Offline);

    // A beat landed between the expiry and the watcher: they're back.
    $service->heartbeat($alice);
    $service->expired($key);
    expect(collect(presenceChanges())->map(fn (PresenceChanged $event) => $event->status)->all())
        ->toBe([PresenceStatus::Offline, PresenceStatus::Online]);
});

test('ignores every other key Redis expires', function () {
    [$alice, $bob] = User::factory()->count(2)->create();
    sharedConversation($alice, $bob);
    $prefix = config('database.redis.options.prefix');

    app(PresenceService::class)->expired("{$prefix}some-session-id");
    app(PresenceService::class)->expired("presence:online:{$alice->id}");

    Event::assertNotDispatched(PresenceChanged::class);
});

test('nobody is told about someone nobody sees, nor by a deleted group', function () {
    [$alice, $bob] = User::factory()->count(2)->create();
    $deleted = sharedConversation($alice, $bob);
    $deleted->forceFill(['deleted_at' => now()])->save();

    $this->actingAs($alice)->postJson('/api/presence/heartbeat')->assertNoContent();

    Event::assertNotDispatched(PresenceChanged::class);
});
