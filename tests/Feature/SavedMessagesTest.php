<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\SavedMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Alice and Bob in a group called Team, and Carol, who isn't in it.
 *
 * @return array{0: User, 1: User, 2: User, 3: Conversation}
 */
function savedGroup(): array
{
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $carol = User::factory()->create(['name' => 'Carol']);

    $group = Conversation::factory()->group('Team')->create(['created_by_user_id' => $alice->id]);
    savedJoin($alice, $group);
    savedJoin($bob, $group);

    return [$alice, $bob, $carol, $group];
}

function savedJoin(User $user, Conversation $conversation): void
{
    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'role' => 'participant',
        'joined_at' => now(),
    ]);
}

function savedDirect(User $one, User $other): Conversation
{
    $conversation = Conversation::factory()->create(['created_by_user_id' => $one->id]);
    savedJoin($one, $conversation);
    savedJoin($other, $conversation);

    return $conversation;
}

/** One at a time, so the UUIDv7 ids are strictly increasing. */
function savedPost(Conversation $conversation, User $sender, string $body = 'Ship it on Friday.'): Message
{
    return Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $sender->id,
        'body' => $body,
    ]);
}

function saveUrl(Message $message): string
{
    return "/api/messages/{$message->id}/save";
}

function savedLeave(User $user, Conversation $conversation, Message $lastSeen): void
{
    ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $user->id)
        ->update(['left_at' => now(), 'left_at_message_id' => $lastSeen->id]);
}

/** @return array<int, string> */
function savedIds(TestResponse $response): array
{
    return collect($response->json('data'))->pluck('message.id')->all();
}

test('saves anyone’s message or your own, once however many times it’s saved', function () {
    [$alice, $bob, , $group] = savedGroup();
    $theirs = savedPost($group, $bob);
    $mine = savedPost($group, $alice);

    $this->actingAs($alice)->postJson(saveUrl($theirs))->assertNoContent();
    $this->actingAs($alice)->postJson(saveUrl($theirs))->assertNoContent();
    $this->actingAs($alice)->postJson(saveUrl($mine))->assertNoContent();

    expect(SavedMessage::where('user_id', $alice->id)->count())->toBe(2);
    $this->actingAs($alice)->getJson('/api/saved-messages/ids')
        ->assertOk()
        ->assertExactJson(['data' => [$theirs->id, $mine->id]]);
});

test('lists what was saved, most recently saved first, with the conversation each is in', function () {
    [$alice, $bob, , $group] = savedGroup();
    $direct = savedDirect($alice, $bob);
    $first = savedPost($group, $bob, 'Standup moves to 10:30.');
    $second = savedPost($group, $alice, 'I’ll book the room.');
    $third = savedPost($direct, $bob, 'The address is 12 Elm Street.');

    // Saved in a different order from the one they were sent in.
    foreach ([$second, $third, $first] as $message) {
        $this->actingAs($alice)->postJson(saveUrl($message))->assertNoContent();
    }

    $response = $this->actingAs($alice)->getJson('/api/saved-messages')->assertOk();

    expect(savedIds($response))->toBe([$first->id, $third->id, $second->id]);
    $response->assertJsonPath('data.0.message.body', 'Standup moves to 10:30.')
        ->assertJsonPath('data.0.message.sender.name', 'Bob')
        ->assertJsonPath('data.0.conversation', ['id' => $group->id, 'type' => 'group', 'title' => 'Team'])
        ->assertJsonPath('data.1.conversation', ['id' => $direct->id, 'type' => 'direct', 'title' => 'Bob'])
        ->assertJsonPath('data.2.message.sender.name', 'Alice')
        ->assertJsonPath('meta', ['has_more' => false, 'next_before_id' => null]);
    expect($response->json('data.0.saved_at'))->not->toBeNull();
});

test('reads the list a page at a time', function () {
    [$alice, $bob, , $group] = savedGroup();
    $messages = [savedPost($group, $bob, 'one'), savedPost($group, $bob, 'two'), savedPost($group, $bob, 'three')];
    foreach ($messages as $message) {
        $this->actingAs($alice)->postJson(saveUrl($message))->assertNoContent();
    }

    $page = $this->actingAs($alice)->getJson('/api/saved-messages?limit=2')->assertOk();

    expect(savedIds($page))->toBe([$messages[2]->id, $messages[1]->id]);
    $page->assertJsonPath('meta.has_more', true)
        ->assertJsonPath('meta.next_before_id', $page->json('data.1.id'));

    $next = $this->actingAs($alice)
        ->getJson('/api/saved-messages?limit=2&before_id='.$page->json('meta.next_before_id'))
        ->assertOk();

    expect(savedIds($next))->toBe([$messages[0]->id]);
    $next->assertJsonPath('meta', ['has_more' => false, 'next_before_id' => null]);
});

test('turns away a cursor that isn’t a uuid', function () {
    [$alice] = savedGroup();

    $this->actingAs($alice)->getJson('/api/saved-messages?before_id=nope')->assertUnprocessable();
});

// Strict, on purpose: even a message from before they left stays out.
test('keeps a conversation the viewer left out of the list, and brings it back when they’re added again', function () {
    [$alice, $bob, , $group] = savedGroup();
    $message = savedPost($group, $bob);
    $this->actingAs($alice)->postJson(saveUrl($message))->assertNoContent();

    savedLeave($alice, $group, savedPost($group, $bob, 'Alice left'));

    $this->actingAs($alice)->getJson('/api/saved-messages')->assertOk()->assertJsonCount(0, 'data');
    // The row is kept for them.
    $this->actingAs($alice)->getJson('/api/saved-messages/ids')->assertExactJson(['data' => [$message->id]]);

    ConversationParticipant::where('conversation_id', $group->id)
        ->where('user_id', $alice->id)
        ->update(['left_at' => null, 'left_at_message_id' => null]);

    expect(savedIds($this->actingAs($alice)->getJson('/api/saved-messages')))->toBe([$message->id]);
});

test('leaves out a message deleted since, and everything in a deleted group', function () {
    [$alice, $bob, , $group] = savedGroup();
    $direct = savedDirect($alice, $bob);
    $deleted = savedPost($group, $bob, 'Wrong chat, sorry');
    $inGroup = savedPost($group, $bob);
    $kept = savedPost($direct, $bob);
    foreach ([$deleted, $inGroup, $kept] as $message) {
        $this->actingAs($alice)->postJson(saveUrl($message))->assertNoContent();
    }

    $deleted->forceFill(['body' => '', 'deleted_at' => now()])->save();
    expect(savedIds($this->actingAs($alice)->getJson('/api/saved-messages')))->toBe([$kept->id, $inGroup->id]);

    $group->forceFill(['deleted_at' => now()])->save();
    expect(savedIds($this->actingAs($alice)->getJson('/api/saved-messages')))->toBe([$kept->id]);
});

test('shows nobody else’s saved messages', function () {
    [$alice, $bob, , $group] = savedGroup();
    $this->actingAs($bob)->postJson(saveUrl(savedPost($group, $alice)))->assertNoContent();

    $this->actingAs($alice)->getJson('/api/saved-messages')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($alice)->getJson('/api/saved-messages/ids')->assertExactJson(['data' => []]);
});

test('won’t save in a conversation the viewer isn’t in, or isn’t in any more', function () {
    [$alice, $bob, $carol, $group] = savedGroup();
    $message = savedPost($group, $bob);

    $this->actingAs($carol)->postJson(saveUrl($message))->assertForbidden();

    savedLeave($alice, $group, savedPost($group, $bob, 'Alice left'));
    $this->actingAs($alice)->postJson(saveUrl($message))->assertForbidden();

    expect(SavedMessage::count())->toBe(0);
});

test('won’t save a group event line, a deleted message, or anything in a deleted group', function () {
    [$alice, $bob, , $group] = savedGroup();
    $event = Message::factory()->create([
        'conversation_id' => $group->id,
        'sender_user_id' => null,
        'type' => 'system',
        'event_type' => 'member_added',
        'body' => 'Alice added Bob',
    ]);
    $deleted = savedPost($group, $bob);
    $deleted->forceFill(['body' => '', 'deleted_at' => now()])->save();
    $message = savedPost($group, $bob);

    $this->actingAs($alice)->postJson(saveUrl($event))->assertNotFound();
    $this->actingAs($alice)->postJson(saveUrl($deleted))->assertNotFound();

    $group->forceFill(['deleted_at' => now()])->save();
    $this->actingAs($alice)->postJson(saveUrl($message))->assertNotFound();
    // Gone for everyone, as with the history: even someone never in it.
    $this->actingAs(User::factory()->create())->postJson(saveUrl($message))->assertNotFound();

    expect(SavedMessage::count())->toBe(0);
});

test('a message that doesn’t exist can’t be saved, whatever the id looks like', function () {
    [$alice] = savedGroup();

    $this->actingAs($alice)->postJson('/api/messages/'.Str::uuid7().'/save')->assertNotFound();
    $this->actingAs($alice)->postJson('/api/messages/not-a-uuid/save')->assertNotFound();
});

test('takes a message off the list, whether or not it was on it', function () {
    [$alice, $bob, , $group] = savedGroup();
    $message = savedPost($group, $bob);
    $this->actingAs($alice)->postJson(saveUrl($message))->assertNoContent();
    $this->actingAs($bob)->postJson(saveUrl($message))->assertNoContent();

    $this->actingAs($alice)->deleteJson(saveUrl($message))->assertNoContent();
    $this->actingAs($alice)->deleteJson(saveUrl($message))->assertNoContent();

    // Only Alice's own save went.
    expect(SavedMessage::pluck('user_id')->all())->toBe([$bob->id]);
});

// Removing their own row says nothing new, so it isn't held to saving's rule.
test('lets someone who left, or whose message was deleted, still remove it, but nobody outside', function () {
    [$alice, $bob, $carol, $group] = savedGroup();
    $deleted = savedPost($group, $bob);
    $earlier = savedPost($group, $bob);
    foreach ([$deleted, $earlier] as $message) {
        $this->actingAs($alice)->postJson(saveUrl($message))->assertNoContent();
    }

    $this->actingAs($carol)->deleteJson(saveUrl($earlier))->assertForbidden();

    $deleted->forceFill(['body' => '', 'deleted_at' => now()])->save();
    $this->actingAs($alice)->deleteJson(saveUrl($deleted))->assertNoContent();

    savedLeave($alice, $group, savedPost($group, $bob, 'Alice left'));
    $this->actingAs($alice)->deleteJson(saveUrl($earlier))->assertNoContent();

    expect(SavedMessage::count())->toBe(0);
});

test('you have to be signed in', function () {
    [, $bob, , $group] = savedGroup();
    $message = savedPost($group, $bob);

    $this->getJson('/api/saved-messages')->assertUnauthorized();
    $this->getJson('/api/saved-messages/ids')->assertUnauthorized();
    $this->postJson(saveUrl($message))->assertUnauthorized();
    $this->deleteJson(saveUrl($message))->assertUnauthorized();
});

test('the list costs the same few queries however many conversations it spans', function () {
    $queriesWith = function (int $people): int {
        $alice = User::factory()->create();

        foreach (User::factory()->count($people)->create() as $person) {
            $direct = savedDirect($alice, $person);
            $this->actingAs($alice)->postJson(saveUrl(savedPost($direct, $person)))->assertNoContent();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($alice)->getJson('/api/saved-messages')->assertOk()->assertJsonCount($people, 'data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesWith(2))->toBe($queriesWith(6));
});

test('saving is throttled at sixty a minute, on its own counter', function () {
    [$alice, $bob, , $group] = savedGroup();
    $message = savedPost($group, $bob);

    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($alice)->postJson(saveUrl($message))->assertNoContent();
    }

    $this->actingAs($alice)->postJson(saveUrl($message))->assertTooManyRequests();
    $this->actingAs($alice)->deleteJson(saveUrl($message))->assertNoContent();
});
