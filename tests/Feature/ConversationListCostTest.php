<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

// The chat list and the contacts list are fetched again whenever a client
// can't tell what changed, by every member of a conversation at once. What
// one costs mustn't grow with how many conversations, or contacts, it holds.

/**
 * `$count` conversations for the viewer, each with someone else in it who
 * has written to them, and with a group among them.
 *
 * @return list<User> the other people
 */
function conversationsFor(User $viewer, int $count): array
{
    $others = [];
    for ($i = 0; $i < $count; $i++) {
        $other = User::factory()->create();
        $others[] = $other;
        $conversation = $i % 3 === 0
            ? Conversation::factory()->group("Group {$i}")->create(['created_by_user_id' => $viewer->id])
            : Conversation::factory()->create(['created_by_user_id' => $viewer->id]);
        foreach ([$viewer, $other] as $user) {
            ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);
        }
        $last = Message::factory()->count(2)->create(['conversation_id' => $conversation->id, 'sender_user_id' => $other->id])->last();
        $conversation->forceFill(['last_message_id' => $last->id, 'last_message_at' => now()])->save();
    }

    return $others;
}

/**
 * The database queries and the Redis round trips one request makes.
 *
 * @return array{queries: int, redis: int}
 */
function costOf(callable $request): array
{
    $redis = 0;
    Redis::enableEvents();
    Event::listen(CommandExecuted::class, function () use (&$redis): void {
        $redis++;
    });
    DB::flushQueryLog();
    DB::enableQueryLog();

    $request();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return ['queries' => $queries, 'redis' => $redis];
}

test('the chat list costs the same however many conversations it holds', function () {
    $costWith = function (int $conversations): array {
        $viewer = User::factory()->create();
        conversationsFor($viewer, $conversations);

        return costOf(fn () => $this->actingAs($viewer)->getJson('/api/conversations')->assertOk()->assertJsonCount($conversations, 'data'));
    };

    expect($costWith(1))->toBe($costWith(12));
});

test('the contacts list costs the same however many contacts it holds', function () {
    $costWith = function (int $contacts): array {
        $viewer = User::factory()->create();
        foreach (User::factory()->count($contacts)->create() as $contact) {
            Contact::create(['user_id' => $viewer->id, 'contact_user_id' => $contact->id]);
        }

        return costOf(fn () => $this->actingAs($viewer)->getJson('/api/contacts')->assertOk()->assertJsonCount($contacts, 'data'));
    };

    expect($costWith(1))->toBe($costWith(12));
});

test('a group’s member list costs the same however many members it has', function () {
    $costWith = function (int $members): array {
        $users = User::factory()->count($members)->create();
        $group = Conversation::factory()->group('Everyone')->create(['created_by_user_id' => $users[0]->id]);
        foreach ($users as $user) {
            ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);
        }

        return costOf(fn () => $this->actingAs($users[0])->getJson("/api/conversations/{$group->id}/participants")->assertOk()->assertJsonCount($members, 'data'));
    };

    expect($costWith(2))->toBe($costWith(12));
});

test('the chat list counts what the viewer hasn’t read: others’ messages after their pointer, never their own or group events', function () {
    [$viewer, $ben, $cal] = User::factory()->count(3)->create()->all();

    $talk = function (array $members, ?string $title = null) {
        $conversation = $title ? Conversation::factory()->group($title)->create(['created_by_user_id' => $members[0]->id]) : Conversation::factory()->create(['created_by_user_id' => $members[0]->id]);
        foreach ($members as $user) {
            ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);
        }

        return $conversation;
    };
    $say = fn (Conversation $c, ?User $from, string $type = 'user') => Message::factory()->create([
        'conversation_id' => $c->id, 'sender_user_id' => $from?->id, 'type' => $type, 'body' => $type === 'user' ? 'hi' : '', 'event_type' => $type === 'user' ? null : 'member_added',
    ]);

    // Read up to Ben's first message; two more from him, one from the viewer, one group event.
    $partlyRead = $talk([$viewer, $ben]);
    $read = $say($partlyRead, $ben);
    $say($partlyRead, $ben);
    $say($partlyRead, $ben);
    $say($partlyRead, $viewer);
    $say($partlyRead, null, 'system');
    ConversationParticipant::where('conversation_id', $partlyRead->id)->where('user_id', $viewer->id)->update(['last_read_message_id' => $read->id]);

    // Never opened: everything others wrote counts.
    $neverRead = $talk([$viewer, $ben, $cal], 'Never read');
    $say($neverRead, $ben);
    $say($neverRead, $cal);
    $say($neverRead, null, 'system');

    // Left: frozen, and nothing in it is waiting to be read.
    $left = $talk([$viewer, $cal], 'Left');
    $say($left, $cal);
    ConversationParticipant::where('conversation_id', $left->id)->where('user_id', $viewer->id)->update(['left_at' => now()]);

    $unread = collect($this->actingAs($viewer)->getJson('/api/conversations')->assertOk()->json('data'))->pluck('unread_count', 'id');

    expect($unread->all())->toEqual([
        $partlyRead->id => 2,
        $neverRead->id => 2,
        $left->id => 0,
    ]);
});
