<?php

use App\Events\ConversationDeleted;
use App\Events\ConversationParticipantsUpdated;
use App\Events\ConversationRead;
use App\Events\MessageReactionUpdated;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Events\TypingIndicator;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Who hears what happens in a conversation. Reverb checks who may listen to
// a channel once, when a socket subscribes, so a member removed from a group
// kept hearing its channel until they disconnected. Everything now goes to
// each current member's own channel, worked out when it goes out.

/**
 * Ann (the admin), Ben and Cal in a group with one message from Ann, and
 * Dee, who has left it.
 *
 * @return array{0: User, 1: User, 2: User, 3: User, 4: Conversation, 5: Message}
 */
function audienceGroup(): array
{
    [$ann, $ben, $cal, $dee] = User::factory()->count(4)->create()->all();
    $group = Conversation::factory()->group('Crew')->create(['created_by_user_id' => $ann->id]);

    foreach ([[$ann, 'admin'], [$ben, 'participant'], [$cal, 'participant'], [$dee, 'participant']] as [$user, $role]) {
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
    }
    ConversationParticipant::where('conversation_id', $group->id)->where('user_id', $dee->id)->update(['left_at' => now()]);

    $message = Message::factory()->create(['conversation_id' => $group->id, 'sender_user_id' => $ann->id]);

    return [$ann, $ben, $cal, $dee, $group, $message];
}

/** The event for something Ann does in the group. */
function raisedByAnn(string $kind, User $ann, Message $message): object
{
    return match ($kind) {
        'a message' => new MessageSent($message),
        'an edit' => new MessageUpdated($message),
        'a reaction' => new MessageReactionUpdated($message),
        'a read receipt' => new ConversationRead(tap(
            ConversationParticipant::where('conversation_id', $message->conversation_id)->where('user_id', $ann->id)->sole(),
            fn (ConversationParticipant $participant) => $participant->update(['last_read_message_id' => $message->id]),
        )),
        'typing' => new TypingIndicator($message->conversation_id, $ann),
    };
}

/**
 * The channels an event goes out on, asked now, as the queue worker would.
 *
 * @return array<int, string>
 */
function toldOn(object $event): array
{
    $names = array_map(fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
    sort($names);

    return $names;
}

/** @return array<int, string> */
function ownChannelsOf(User ...$users): array
{
    $names = array_map(fn (User $user): string => "private-App.Models.User.{$user->id}", $users);
    sort($names);

    return $names;
}

dataset('what happens in a conversation', ['a message', 'an edit', 'a reaction', 'a read receipt', 'typing']);

test('goes to each current member’s own channel and nowhere else', function (string $kind) {
    [$ann, $ben, $cal, , , $message] = audienceGroup();

    $event = raisedByAnn($kind, $ann, $message);

    // Not to Dee, who left; and someone typing isn't told they are.
    expect(toldOn($event))->toBe($kind === 'typing' ? ownChannelsOf($ben, $cal) : ownChannelsOf($ann, $ben, $cal));
})->with('what happens in a conversation');

test('isn’t told to a member removed between its happening and its going out', function (string $kind) {
    [$ann, $ben, $cal, , $group, $message] = audienceGroup();
    $event = raisedByAnn($kind, $ann, $message);

    $this->actingAs($ann)->deleteJson("/api/conversations/{$group->id}/participants/{$ben->id}/kick")->assertOk();

    expect(toldOn($event))
        ->not->toContain("private-App.Models.User.{$ben->id}")
        ->toContain("private-App.Models.User.{$cal->id}");
})->with('what happens in a conversation');

test('isn’t told to anyone once the group is deleted', function (string $kind) {
    [$ann, , , , $group, $message] = audienceGroup();
    $event = raisedByAnn($kind, $ann, $message);

    $this->actingAs($ann)->deleteJson("/api/conversations/{$group->id}")->assertNoContent();

    expect($event->broadcastOn())->toBe([]);
})->with('what happens in a conversation');

test('membership changes and a deletion go to people’s own channels too', function () {
    [, $ben, , , $group] = audienceGroup();

    foreach ([new ConversationParticipantsUpdated($group, $ben), new ConversationDeleted($group)] as $event) {
        expect(toldOn($event))->not->toBeEmpty()->each->toStartWith('private-App.Models.User.');
    }
});

test('nobody can listen to a conversation’s channel, not even a current member', function () {
    [$ann, , , $dee, $group] = audienceGroup();

    useLocalPusherAuth();

    foreach ([$ann, $dee] as $user) {
        $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => "private-conversation.{$group->id}",
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }
});

test('everyone can listen to their own channel, and only their own', function () {
    [$ann, $ben] = audienceGroup();

    useLocalPusherAuth();

    $this->actingAs($ann)->postJson('/broadcasting/auth', [
        'channel_name' => "private-App.Models.User.{$ann->id}",
        'socket_id' => '1234.5678',
    ])->assertOk();
    $this->actingAs($ann)->postJson('/broadcasting/auth', [
        'channel_name' => "private-App.Models.User.{$ben->id}",
        'socket_id' => '1234.5678',
    ])->assertForbidden();
});

// Reverb relays a socket's own "client-" events only for these two settings
// (Laravel\Reverb\Protocols\Pusher\ClientEvent). The app sends none.
test('Reverb relays no client events from one socket to another', function () {
    expect(config('reverb.apps.apps.0.accept_client_events_from'))->not->toBeIn(['all', 'members']);
});
