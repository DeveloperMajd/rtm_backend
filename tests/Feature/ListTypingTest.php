<?php

use App\Events\TypingIndicator;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Alice, Bob and Carol in a group, and Dave, who has left it.
 *
 * @return array{0: User, 1: User, 2: User, 3: User, 4: Conversation}
 */
function typingGroup(int $extraMembers = 0): array
{
    [$alice, $bob, $carol, $dave] = User::factory()->count(4)->create()->all();
    $group = Conversation::factory()->group('Team')->create(['created_by_user_id' => $alice->id]);

    $members = [$alice, $bob, $carol, ...User::factory()->count($extraMembers)->create()->all()];
    foreach ($members as $user) {
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);
    }
    ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $dave->id, 'role' => 'participant', 'joined_at' => now(), 'left_at' => now()]);

    return [$alice, $bob, $carol, $dave, $group];
}

/** @return array<int, string> */
function typingChannels(TypingIndicator $event): array
{
    $names = array_map(fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
    sort($names);

    return $names;
}

test('tells the conversation and each other current member’s own channel, in one broadcast', function () {
    Event::fake([TypingIndicator::class]);
    [$alice, $bob, $carol, , $group] = typingGroup();

    $this->actingAs($alice)->postJson("/api/conversations/{$group->id}/typing")->assertNoContent();

    Event::assertDispatchedTimes(TypingIndicator::class, 1);
    $expected = ["private-App.Models.User.{$bob->id}", "private-App.Models.User.{$carol->id}", "private-conversation.{$group->id}"];
    sort($expected);
    // Never the typist's own channel, nor someone who has left.
    Event::assertDispatched(TypingIndicator::class, fn (TypingIndicator $event): bool => typingChannels($event) === $expected);
});

test('says which conversation it is, so a chat list knows which row to mark', function () {
    Event::fake([TypingIndicator::class]);
    [$alice, , , , $group] = typingGroup();

    $this->actingAs($alice)->postJson("/api/conversations/{$group->id}/typing")->assertNoContent();

    Event::assertDispatched(TypingIndicator::class, fn (TypingIndicator $event): bool => $event->broadcastWith() === [
        'conversation_id' => $group->id,
        'user_id' => $alice->id,
        'name' => $alice->name,
    ]);
});

test('still turns away someone who has left the group', function () {
    Event::fake([TypingIndicator::class]);
    [, , , $dave, $group] = typingGroup();

    $this->actingAs($dave)->postJson("/api/conversations/{$group->id}/typing")->assertForbidden();

    Event::assertNotDispatched(TypingIndicator::class);
});

test('costs the same few queries however many people are in the group', function () {
    Event::fake([TypingIndicator::class]);

    $queriesWith = function (int $extraMembers): int {
        [$alice, , , , $group] = typingGroup($extraMembers);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($alice)->postJson("/api/conversations/{$group->id}/typing")->assertNoContent();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesWith(0))->toBe($queriesWith(12));
});
