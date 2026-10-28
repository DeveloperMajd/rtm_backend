<?php

use App\Events\ConversationParticipantsUpdated;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationParticipantService;
use App\Services\PresenceService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function () {
    Redis::flushdb();
});

// Someone who left a group, or was removed, sees it as it was when they
// went: its name and who was in it, with their roles. Nothing that happens
// after reaches them: not who joins or leaves, not a new admin, not a new
// name, not who's online.

/**
 * Ann (the admin), Ben, Cal and Dee in "Crew", each seen recently, with
 * one message from Ann.
 *
 * @return array{ann: User, ben: User, cal: User, dee: User, group: Conversation}
 */
function crew(): array
{
    [$ann, $ben, $cal, $dee] = User::factory()->count(4)->create(['last_seen_at' => now()->subMinutes(5)])->all();
    $group = Conversation::factory()->group('Crew')->create(['created_by_user_id' => $ann->id]);

    foreach ([[$ann, 'admin'], [$ben, 'participant'], [$cal, 'participant'], [$dee, 'participant']] as [$user, $role]) {
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
    }

    test()->actingAs($ann)->postJson('/api/messages', ['conversation_id' => $group->id, 'body' => 'launch plan'])->assertCreated();

    return ['ann' => $ann, 'ben' => $ben, 'cal' => $cal, 'dee' => $dee, 'group' => $group];
}

/**
 * After Dee has gone: the group is renamed, Eve joins, Ben is made an
 * admin, Cal leaves, Ann writes again and is online.
 */
function carryOnWithout(array $crew): User
{
    $service = app(ConversationParticipantService::class);
    $eve = User::factory()->create(['last_seen_at' => now()]);

    test()->travel(1)->minutes();
    $service->rename($crew['group'], $crew['ann'], 'Crew 2');
    $service->addParticipant($crew['group'], $crew['ann'], $eve);
    $service->updateRole($crew['group'], $crew['ann'], $crew['ben'], 'admin');
    $service->leave($crew['group'], $crew['cal']);
    test()->actingAs($crew['ann'])->postJson('/api/messages', ['conversation_id' => $crew['group']->id, 'body' => 'still here'])->assertCreated();
    app(PresenceService::class)->heartbeat($crew['ann']);

    return $eve;
}

/** @return array<string, mixed> */
function groupAsSeenBy(User $viewer, Conversation $group): array
{
    $list = test()->actingAs($viewer)->getJson('/api/conversations')->assertOk();

    return collect($list->json('data'))->firstWhere('id', $group->id);
}

dataset('ways out', [
    'they left' => [fn (array $crew) => app(ConversationParticipantService::class)->leave($crew['group'], $crew['dee'])],
    'they were removed' => [fn (array $crew) => app(ConversationParticipantService::class)->kickParticipant($crew['group'], $crew['ann'], $crew['dee'])],
]);

test('the group stays as it was when they went', function (Closure $out) {
    $crew = crew();
    // Someone who had already left by then.
    $gone = User::factory()->create();
    app(ConversationParticipantService::class)->addParticipant($crew['group'], $crew['ann'], $gone);
    app(ConversationParticipantService::class)->leave($crew['group'], $gone);
    $out($crew);
    $leftAt = ConversationParticipant::where('user_id', $crew['dee']->id)->sole()->left_at;
    carryOnWithout($crew);
    // Four in it now, against three when they went.
    app(ConversationParticipantService::class)->addParticipant($crew['group'], $crew['ann'], User::factory()->create());

    foreach ([
        groupAsSeenBy($crew['dee'], $crew['group']),
        test()->actingAs($crew['dee'])->getJson("/api/conversations/{$crew['group']->id}")->assertOk()->json('data'),
    ] as $seen) {
        expect($seen['title'])->toBe('Crew')
            ->and($seen['participants_count'])->toBe(3)
            ->and(strtotime($seen['updated_at']))->toBeLessThanOrEqual($leftAt->getTimestamp());

        $members = collect($seen['participants'])->whereNull('left_at');
        // Cal left after them, so was still in it; Eve joined after; Ben
        // was made an admin after. Not whoever had left before them.
        expect($members->pluck('role', 'user_id')->all())->toBe([
            $crew['ann']->id => 'admin',
            $crew['ben']->id => 'participant',
            $crew['cal']->id => 'participant',
        ]);
        // Nothing live: Ann is online, and everyone's last seen is public.
        expect($members->pluck('presence_status')->unique()->all())->toBe(['offline'])
            ->and($members->pluck('is_online')->unique()->all())->toBe([false])
            ->and($members->pluck('last_seen_at')->unique()->all())->toBe([null]);

        // They're in it themselves, as having left.
        expect(collect($seen['participants'])->firstWhere('user_id', $crew['dee']->id)['left_at'])->not->toBeNull();
    }
})->with('ways out');

test('a search of their history goes by the name it had then', function () {
    $crew = crew();
    app(ConversationParticipantService::class)->leave($crew['group'], $crew['dee']);
    carryOnWithout($crew);

    $found = $this->actingAs($crew['dee'])->getJson('/api/messages/search?q=launch')->assertOk();

    expect($found->json('data.0.conversation_title'))->toBe('Crew');
});

test('added back, they see the group as it is now', function () {
    $crew = crew();
    app(ConversationParticipantService::class)->leave($crew['group'], $crew['dee']);
    $eve = carryOnWithout($crew);

    app(ConversationParticipantService::class)->addParticipant($crew['group'], $crew['ann'], $crew['dee']);
    $seen = groupAsSeenBy($crew['dee'], $crew['group']);

    expect(ConversationParticipant::where('user_id', $crew['dee']->id)->sole()->left_snapshot)->toBeNull();
    expect($seen['title'])->toBe('Crew 2');
    $members = collect($seen['participants'])->whereNull('left_at')->keyBy('user_id');
    expect($members->keys()->sort()->values()->all())->toBe(collect([$crew['ann']->id, $crew['ben']->id, $crew['dee']->id, $eve->id])->sort()->values()->all())
        ->and($members[$crew['ben']->id]['role'])->toBe('admin')
        ->and($members[$crew['ann']->id]['presence_status'])->toBe('online');
});

test('members still in it see each other live, and nothing live of those who left', function () {
    $crew = crew();
    app(ConversationParticipantService::class)->leave($crew['group'], $crew['dee']);
    app(PresenceService::class)->heartbeat($crew['ann']);
    app(PresenceService::class)->heartbeat($crew['dee']);

    $people = collect(groupAsSeenBy($crew['ben'], $crew['group'])['participants'])->keyBy('user_id');

    expect($people[$crew['ann']->id])
        ->presence_status->toBe('online')
        ->last_seen_at->not->toBeNull();
    expect($people[$crew['dee']->id])
        ->left_at->not->toBeNull()
        ->presence_status->toBe('offline')
        ->is_online->toBeFalse()
        ->last_seen_at->toBeNull();
});

test('who’s in the group now is for its members', function () {
    $crew = crew();
    app(ConversationParticipantService::class)->leave($crew['group'], $crew['dee']);

    $this->actingAs($crew['ben'])->getJson("/api/conversations/{$crew['group']->id}/participants")
        ->assertOk()
        ->assertJsonCount(4, 'data');
    $this->actingAs($crew['dee'])->getJson("/api/conversations/{$crew['group']->id}/participants")
        ->assertForbidden();

    $crew['group']->forceFill(['deleted_at' => now()])->save();
    $this->actingAs($crew['ben'])->getJson("/api/conversations/{$crew['group']->id}/participants")
        ->assertNotFound();
});

test('a change to who’s in it goes to its members and whoever it was about, not to those who left', function () {
    $crew = crew();
    app(ConversationParticipantService::class)->leave($crew['group'], $crew['dee']);
    app(ConversationParticipantService::class)->kickParticipant($crew['group'], $crew['ann'], $crew['cal']);
    $crew['group']->load('participants.user');

    $toldOn = fn (object $event): array => collect($event->broadcastOn())
        ->map(fn (PrivateChannel $channel): string => $channel->name)
        ->sort()->values()->all();
    $channels = fn (User ...$users): array => collect($users)
        ->map(fn (User $user): string => "private-App.Models.User.{$user->id}")
        ->sort()->values()->all();

    // Cal has just been removed: told, unlike Dee, who left before.
    expect($toldOn(new ConversationParticipantsUpdated($crew['group'], $crew['cal'])))
        ->toBe($channels($crew['ann'], $crew['ben'], $crew['cal']));
});

// Members who left before the snapshot was kept get the closest their rows
// can tell (the migration).
test('those who left before get a snapshot from the migration', function () {
    $crew = crew();
    $service = app(ConversationParticipantService::class);
    $before = User::factory()->create();
    $service->addParticipant($crew['group'], $crew['ann'], $before);
    $service->leave($crew['group'], $before);
    $service->rename($crew['group'], $crew['ann'], 'Crew 1');

    $this->travel(1)->minutes();
    $service->leave($crew['group'], $crew['dee']);
    $this->travel(1)->minutes();
    $eve = carryOnWithout($crew);
    $service->rename($crew['group'], $crew['ann'], 'Crew 3');

    // As the table was before the migration.
    Schema::table('conversation_participants', fn ($table) => $table->dropColumn('left_snapshot'));
    (require database_path('migrations/2026_10_28_165750_add_left_snapshot_to_conversation_participants_table.php'))->up();

    $snapshot = ConversationParticipant::where('user_id', $crew['dee']->id)->sole()->left_snapshot;

    // Not who had left before them (or themselves), not Eve, who joined
    // after; Cal, who left after them, was still in it. Roles are as they
    // are now; the title is the one the first rename after them changed
    // (Crew 1 → Crew 2), not the first ever (Crew → Crew 1).
    expect($snapshot['title'])->toBe('Crew 1')
        ->and(collect($snapshot['members'])->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$crew['ann']->id, $crew['ben']->id, $crew['cal']->id])->sort()->values()->all());
    expect(collect($snapshot['members'])->pluck('user_id'))->not->toContain($eve->id, $before->id);

    // Someone still in it has none.
    expect(ConversationParticipant::where('user_id', $crew['ann']->id)->sole()->left_snapshot)->toBeNull();
    expect(DB::table('conversation_participants')->whereNotNull('left_at')->whereNull('left_snapshot')->count())->toBe(0);
});
