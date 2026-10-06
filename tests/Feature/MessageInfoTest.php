<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Models\UserSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Alice (admin), bob and Carol, in a group. The names differ in case on
 * purpose: the lists are ordered by name without regard to it.
 *
 * @return array{0: User, 1: User, 2: User, 3: Conversation}
 */
function infoGroup(): array
{
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'bob']);
    $carol = User::factory()->create(['name' => 'Carol']);

    $conversation = Conversation::factory()->group('Team')->create(['created_by_user_id' => $alice->id]);

    foreach ([[$alice, 'admin'], [$bob, 'participant'], [$carol, 'participant']] as [$user, $role]) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    return [$alice, $bob, $carol, $conversation];
}

/** One at a time, so the UUIDv7 ids are strictly increasing. */
function infoMessage(Conversation $conversation, User $sender, string $body = 'Standup moved to 10:30.'): Message
{
    return Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $sender->id,
        'body' => $body,
    ]);
}

function infoUrl(Message $message): string
{
    return "/api/messages/{$message->id}/info";
}

function infoRead(User $user, Conversation $conversation, Message $upTo): void
{
    ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $user->id)
        ->update(['last_read_message_id' => $upTo->id, 'last_read_at' => now()]);
}

function infoLeave(User $user, Conversation $conversation, Message $lastSeen): void
{
    ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $user->id)
        ->update(['left_at' => now(), 'left_at_message_id' => $lastSeen->id]);
}

function infoSettings(User $user, array $settings): void
{
    UserSettings::for($user)->fill($settings)->save();
}

/**
 * @param  array<int, array{name: string}>  $people
 * @return array<int, string>
 */
function infoNames(array $people): array
{
    return collect($people)->pluck('name')->all();
}

test('gives the sender, when it was sent, and when it was edited or deleted', function () {
    [$alice, $bob, , $conversation] = infoGroup();
    $message = infoMessage($conversation, $bob);
    $message->forceFill(['edited_at' => now()])->save();
    $message = $message->fresh();

    $response = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();

    expect($response->json('data.id'))->toBe($message->id);
    expect($response->json('data.sender'))->toBe(['id' => $bob->id, 'name' => 'bob', 'avatar_url' => null]);
    expect($response->json('data.sent_at'))->toBe($message->created_at->toJSON());
    expect($response->json('data.edited_at'))->toBe($message->edited_at->toJSON());
    expect($response->json('data.deleted_at'))->toBeNull();

    $this->actingAs($bob)->deleteJson("/api/messages/{$message->id}")->assertNoContent();

    $deleted = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();
    expect($deleted->json('data.deleted_at'))->not->toBeNull();
    expect($deleted->json('data.sender.id'))->toBe($bob->id);
});

test('on your own message, who has read it and who has not yet, by name', function () {
    [$alice, $bob, $carol, $conversation] = infoGroup();
    $message = infoMessage($conversation, $alice);
    infoRead($carol, $conversation, $message);

    $response = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();

    expect($response->json('data.read_by'))->toBe([
        ['user_id' => $carol->id, 'name' => 'Carol', 'avatar_url' => null],
    ]);
    expect($response->json('data.not_read'))->toBe([
        ['user_id' => $bob->id, 'name' => 'bob', 'avatar_url' => null],
    ]);
    expect($response->json('data.receipts_off'))->toBeFalse();
});

test('reading a later message counts as reading this one, and reading an earlier one does not', function () {
    [$alice, $bob, $carol, $conversation] = infoGroup();
    $earlier = infoMessage($conversation, $bob, 'earlier');
    $message = infoMessage($conversation, $alice);
    $later = infoMessage($conversation, $bob, 'later');

    infoRead($bob, $conversation, $earlier);
    infoRead($carol, $conversation, $later);

    $response = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();

    expect(infoNames($response->json('data.read_by')))->toBe(['Carol']);
    expect(infoNames($response->json('data.not_read')))->toBe(['bob']);
});

test('each list is in name order, whatever the case', function () {
    [$alice, $bob, $carol, $conversation] = infoGroup();
    $message = infoMessage($conversation, $alice);

    // Plain string order would put "Carol" before "bob".
    $response = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();
    expect(infoNames($response->json('data.not_read')))->toBe(['bob', 'Carol']);

    infoRead($bob, $conversation, $message);
    infoRead($carol, $conversation, $message);

    $response = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();
    expect(infoNames($response->json('data.read_by')))->toBe(['bob', 'Carol']);
    expect($response->json('data.not_read'))->toBe([]);
});

test('in a direct conversation there is just the one other person', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'bob']);
    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);

    foreach ([$alice, $bob] as $user) {
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);
    }

    $message = infoMessage($conversation, $alice);

    $before = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();
    expect(infoNames($before->json('data.read_by')))->toBe([]);
    expect(infoNames($before->json('data.not_read')))->toBe(['bob']);

    infoRead($bob, $conversation, $message);

    $after = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();
    expect(infoNames($after->json('data.read_by')))->toBe(['bob']);
    expect(infoNames($after->json('data.not_read')))->toBe([]);
});

test('on someone else\'s message there are no lists to show', function () {
    [$alice, $bob, , $conversation] = infoGroup();
    $message = infoMessage($conversation, $bob);
    infoRead($alice, $conversation, $message);

    $response = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();

    expect($response->json('data.read_by'))->toBeNull();
    expect($response->json('data.not_read'))->toBeNull();
    expect($response->json('data.receipts_off'))->toBeFalse();
});

// They read as "not yet", like anyone who hasn't: the list must not give away
// who has the setting off, and "not yet" is what GET /reads shows for them too.
test('someone who does not share their read state is never listed as having read it', function () {
    [$alice, $bob, $carol, $conversation] = infoGroup();
    $message = infoMessage($conversation, $alice);
    infoRead($bob, $conversation, $message);
    infoRead($carol, $conversation, $message);
    infoSettings($bob, ['read_receipts' => false]);

    $response = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();

    expect(infoNames($response->json('data.read_by')))->toBe(['Carol']);
    expect(infoNames($response->json('data.not_read')))->toBe(['bob']);
});

test('while the viewer’s own read receipts are off they still get the lists, and are told so', function () {
    [$alice, $bob, , $conversation] = infoGroup();
    $message = infoMessage($conversation, $alice);
    infoRead($bob, $conversation, $message);
    // Set directly, as for someone who switched before switches were
    // recorded: with nothing recorded, all of the history follows the
    // setting they have now. (Switches that were recorded keep what was
    // shown before them; see ReadReceiptsAtReadTimeTest.)
    infoSettings($alice, ['read_receipts' => false]);

    $response = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();

    expect($response->json('data.read_by'))->toBe([]);
    expect(infoNames($response->json('data.not_read')))->toBe(['bob', 'Carol']);
    expect($response->json('data.receipts_off'))->toBeTrue();

    // Nothing to explain on a message that never had lists.
    $theirs = $this->actingAs($alice)->getJson(infoUrl(infoMessage($conversation, $bob)))->assertOk();
    expect($theirs->json('data.receipts_off'))->toBeFalse();
});

test('only people still in the conversation are listed', function () {
    [$alice, $bob, $carol, $conversation] = infoGroup();
    $message = infoMessage($conversation, $alice);
    infoRead($carol, $conversation, $message);
    infoLeave($carol, $conversation, $message);

    $response = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();

    expect($response->json('data.read_by'))->toBe([]);
    expect(infoNames($response->json('data.not_read')))->toBe(['bob']);
});

test('a member who left still gets the facts of their own old message, but no lists', function () {
    [$alice, $bob, , $conversation] = infoGroup();
    $message = infoMessage($conversation, $alice);
    infoRead($bob, $conversation, $message);
    infoLeave($alice, $conversation, $message);

    $response = $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();

    expect($response->json('data.id'))->toBe($message->id);
    expect($response->json('data.read_by'))->toBeNull();
    expect($response->json('data.not_read'))->toBeNull();
    expect($response->json('data.receipts_off'))->toBeFalse();
});

test('someone outside the conversation is refused', function () {
    [, $bob, , $conversation] = infoGroup();
    $message = infoMessage($conversation, $bob);

    $this->actingAs(User::factory()->create())->getJson(infoUrl($message))->assertForbidden();
});

test('a message after the viewer left is not found', function () {
    [$alice, $bob, , $conversation] = infoGroup();
    $before = infoMessage($conversation, $bob, 'before');
    infoLeave($alice, $conversation, $before);
    $after = infoMessage($conversation, $bob, 'after');

    $this->actingAs($alice)->getJson(infoUrl($before))->assertOk();
    $this->actingAs($alice)->getJson(infoUrl($after))->assertNotFound();
});

test('a deleted group is gone', function () {
    [$alice, $bob, , $conversation] = infoGroup();
    $message = infoMessage($conversation, $bob);
    $conversation->forceFill(['deleted_at' => now()])->save();

    $this->actingAs($alice)->getJson(infoUrl($message))->assertNotFound();
});

test('a group event line has nothing to say', function () {
    [$alice, , , $conversation] = infoGroup();
    $event = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => null,
        'type' => 'system',
        'event_type' => 'member_added',
        'body' => 'Alice added bob',
    ]);

    $this->actingAs($alice)->getJson(infoUrl($event))->assertNotFound();
});

test('a message that does not exist is not found, whatever the id looks like', function () {
    [$alice] = infoGroup();

    $this->actingAs($alice)->getJson('/api/messages/'.Str::uuid7().'/info')->assertNotFound();
    $this->actingAs($alice)->getJson('/api/messages/not-a-uuid/info')->assertNotFound();
});

test('you have to be signed in', function () {
    [, $bob, , $conversation] = infoGroup();

    $this->getJson(infoUrl(infoMessage($conversation, $bob)))->assertUnauthorized();
});

test('the lists cost the same few queries however many people are in the group', function () {
    $queriesWith = function (int $people): int {
        [$alice, , , $conversation] = infoGroup();
        $message = infoMessage($conversation, $alice);

        foreach (User::factory()->count($people)->create() as $person) {
            ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $person->id, 'role' => 'participant', 'joined_at' => now()]);
            infoRead($person, $conversation, $message);
            infoSettings($person, ['read_receipts' => false]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesWith(2))->toBe($queriesWith(8));
});

test('is throttled at sixty a minute, on its own counter', function () {
    [$alice, $bob, , $conversation] = infoGroup();
    $message = infoMessage($conversation, $bob);

    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($alice)->getJson(infoUrl($message))->assertOk();
    }

    $this->actingAs($alice)->getJson(infoUrl($message))->assertTooManyRequests();
    $this->actingAs($alice)->getJson("/api/conversations/{$conversation->id}/reads")->assertOk();
});
