<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('search returns only messages from conversations the user participates in', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $outsider = User::factory()->create();

    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $bob->id, 'role' => 'participant', 'joined_at' => now()]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $bob->id, 'body' => 'lets meet at the coffee shop']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=coffee');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);

    $response = $this->actingAs($outsider)->getJson('/api/messages/search?q=coffee');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

test('search matches on word stems and ignores irrelevant messages', function () {
    $alice = User::factory()->create();
    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'I am running to the store']);
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'completely unrelated message']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=run');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.body'))->toBe('I am running to the store');
});

test('search handles free-text multi-word input without a syntax error', function () {
    $alice = User::factory()->create();
    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'the quick brown fox']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=quick fox');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

test('a direct conversation search result shows the other participant as the title', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $bob->id, 'role' => 'participant', 'joined_at' => now()]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $bob->id, 'body' => 'searchable keyword here']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=searchable');

    $response->assertOk();
    expect($response->json('data.0.conversation_title'))->toBe('Bob');
});

test('query shorter than 2 characters is rejected', function () {
    $alice = User::factory()->create();

    $this->actingAs($alice)->getJson('/api/messages/search?q=a')->assertUnprocessable();
});

test('stemmed matches are still found, preserving recall', function () {
    $alice = User::factory()->create();
    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);

    // "universe" and "university" both stem to 'univers' under the English
    // config, so a university-only message still surfaces as a fallback match.
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'I study at the university']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=universe');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

test('a literal word match outranks a match that only exists due to stemming', function () {
    $alice = User::factory()->create();
    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'I study at the university']);
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'look at that universe of stars']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=universe');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    // Both are found (recall), but the literal match ranks first (precision).
    expect($response->json('data.0.body'))->toBe('look at that universe of stars');
});

/**
 * @return array{0: User, 1: User, 2: Conversation}
 */
function searchGroup(string $title = 'Team'): array
{
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $conversation = Conversation::factory()->group($title)->create(['created_by_user_id' => $alice->id]);

    foreach ([[$alice, 'admin'], [$bob, 'participant']] as [$user, $role]) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    return [$alice, $bob, $conversation];
}

/**
 * One at a time, so the UUIDv7 ids are strictly increasing.
 *
 * @param  array<int, string>  $bodies
 * @return array<int, Message>
 */
function searchMessagesIn(Conversation $conversation, User $sender, array $bodies): array
{
    return array_map(fn (string $body) => Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $sender->id,
        'body' => $body,
    ]), $bodies);
}

function joinAs(Conversation $conversation, User $user): void
{
    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'role' => 'participant',
        'joined_at' => now(),
    ]);
}

test('a search can be narrowed to one conversation, and says how many matches there are in all', function () {
    [$alice, $bob, $team] = searchGroup('Team');
    [, , $other] = searchGroup('Other');
    joinAs($other, $alice);

    searchMessagesIn($team, $bob, ['deploy on friday', 'the deploy went fine']);
    searchMessagesIn($other, $bob, ['another deploy elsewhere']);

    $response = $this->actingAs($alice)
        ->getJson("/api/messages/search?q=deploy&conversation_id={$team->id}")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('conversation_id')->unique()->all())->toBe([$team->id]);
    expect($response->json('meta.total'))->toBe(2);

    $everywhere = $this->actingAs($alice)->getJson('/api/messages/search?q=deploy')->assertOk();
    expect($everywhere->json('meta.total'))->toBe(3);
});

test('results can come newest first instead of best match first', function () {
    [$alice, $bob, $team] = searchGroup();
    $messages = searchMessagesIn($team, $bob, ['redis redis redis', 'redis once', 'redis again later']);

    $recent = $this->actingAs($alice)
        ->getJson("/api/messages/search?q=redis&conversation_id={$team->id}&sort=recent")
        ->assertOk();

    expect(collect($recent->json('data'))->pluck('id')->all())
        ->toBe([$messages[2]->id, $messages[1]->id, $messages[0]->id]);

    $this->actingAs($alice)->getJson('/api/messages/search?q=redis&sort=oldest')->assertUnprocessable();
});

test('one conversation returns up to fifty matches, everywhere up to twenty, and the total counts them all', function () {
    [$alice, $bob, $team] = searchGroup();
    searchMessagesIn($team, $bob, array_fill(0, 60, 'the quarterly numbers'));

    $scoped = $this->actingAs($alice)
        ->getJson("/api/messages/search?q=quarterly&conversation_id={$team->id}&limit=500")
        ->assertOk();
    expect($scoped->json('data'))->toHaveCount(50);
    expect($scoped->json('meta.total'))->toBe(60);

    $everywhere = $this->actingAs($alice)->getJson('/api/messages/search?q=quarterly&limit=500')->assertOk();
    expect($everywhere->json('data'))->toHaveCount(20);
    expect($everywhere->json('meta.total'))->toBe(60);
});

test('only a conversation the viewer is in can be searched', function () {
    [, $bob, $team] = searchGroup();
    searchMessagesIn($team, $bob, ['private plans']);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->getJson("/api/messages/search?q=plans&conversation_id={$team->id}")
        ->assertForbidden();
    $this->actingAs($stranger)
        ->getJson('/api/messages/search?q=plans&conversation_id=01a0c996-0000-7000-8000-000000000000')
        ->assertNotFound();
    $this->actingAs($stranger)
        ->getJson('/api/messages/search?q=plans&conversation_id=not-a-uuid')
        ->assertUnprocessable();
});

// D1 in the Phase 2 audit: search checked only for a participant row, so a
// member who left could still find everything said after they left.
test('a member who left finds nothing said after they left, everywhere or in the group', function () {
    [$alice, $bob, $team] = searchGroup();
    $before = searchMessagesIn($team, $bob, ['budget draft one']);

    ConversationParticipant::where('conversation_id', $team->id)
        ->where('user_id', $alice->id)
        ->update(['left_at' => now(), 'left_at_message_id' => $before[0]->id]);

    searchMessagesIn($team, $bob, ['budget draft two']);

    $everywhere = $this->actingAs($alice)->getJson('/api/messages/search?q=budget')->assertOk();
    expect(collect($everywhere->json('data'))->pluck('body')->all())->toBe(['budget draft one']);

    $scoped = $this->actingAs($alice)
        ->getJson("/api/messages/search?q=budget&conversation_id={$team->id}")
        ->assertOk();
    expect(collect($scoped->json('data'))->pluck('body')->all())->toBe(['budget draft one']);
    expect($scoped->json('meta.total'))->toBe(1);
});

test('a deleted group turns up in no search', function () {
    [$alice, $bob, $team] = searchGroup();
    searchMessagesIn($team, $bob, ['launch checklist']);
    $team->forceFill(['deleted_at' => now()])->save();

    $everywhere = $this->actingAs($alice)->getJson('/api/messages/search?q=launch')->assertOk();
    expect($everywhere->json('data'))->toBe([]);

    $this->actingAs($alice)
        ->getJson("/api/messages/search?q=launch&conversation_id={$team->id}")
        ->assertNotFound();
});

test('searching one conversation has its own, larger allowance', function () {
    [$alice, $bob, $team] = searchGroup();
    searchMessagesIn($team, $bob, ['hello there']);

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($alice)->getJson('/api/messages/search?q=hello')->assertOk();
    }
    $this->actingAs($alice)->getJson('/api/messages/search?q=hello')->assertTooManyRequests();

    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($alice)->getJson("/api/messages/search?q=hello&conversation_id={$team->id}")->assertOk();
    }
    $this->actingAs($alice)
        ->getJson("/api/messages/search?q=hello&conversation_id={$team->id}")
        ->assertTooManyRequests();
});
