<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: User, 2: Conversation}
 */
function contextConversation(): array
{
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $conversation = Conversation::factory()->group('Team')->create(['created_by_user_id' => $alice->id]);

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
 * @return array<int, Message>
 */
function contextMessages(Conversation $conversation, User $sender, int $count, string $prefix = 'm'): array
{
    $messages = [];
    for ($i = 1; $i <= $count; $i++) {
        $messages[] = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $sender->id,
            'body' => $prefix.'-'.$i,
        ]);
    }

    return $messages;
}

function contextUrl(Conversation $conversation, Message $message, string $query = ''): string
{
    return "/api/conversations/{$conversation->id}/messages/{$message->id}/context".($query ? "?{$query}" : '');
}

test('returns the message with its neighbours, oldest first, and cursors both ways', function () {
    [$alice, $bob, $conversation] = contextConversation();
    $messages = contextMessages($conversation, $bob, 30);

    $response = $this->actingAs($alice)
        ->getJson(contextUrl($conversation, $messages[14], 'before=3&after=2'))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('body')->all())
        ->toBe(['m-12', 'm-13', 'm-14', 'm-15', 'm-16', 'm-17']);
    expect($response->json('meta'))->toBe([
        'target_id' => $messages[14]->id,
        'has_more_before' => true,
        'has_more_after' => true,
        'next_before_id' => $messages[11]->id,
        'next_after_id' => $messages[16]->id,
    ]);
});

test('the cursors continue through the history endpoint without a gap or a repeat', function () {
    [$alice, $bob, $conversation] = contextConversation();
    $messages = contextMessages($conversation, $bob, 12);

    $window = $this->actingAs($alice)
        ->getJson(contextUrl($conversation, $messages[5], 'before=2&after=2'))
        ->assertOk();

    $older = $this->actingAs($alice)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=50&before_id={$window->json('meta.next_before_id')}")
        ->assertOk();
    $newer = $this->actingAs($alice)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=50&after_id={$window->json('meta.next_after_id')}")
        ->assertOk();

    $all = array_merge(
        collect($older->json('data'))->pluck('body')->all(),
        collect($window->json('data'))->pluck('body')->all(),
        collect($newer->json('data'))->pluck('body')->all(),
    );

    expect($all)->toBe(collect(range(1, 12))->map(fn ($n) => 'm-'.$n)->all());
    expect($older->json('meta.has_more'))->toBeFalse();
    expect($newer->json('meta.has_more'))->toBeFalse();
    expect($newer->json('meta.next_after_id'))->toBeNull();
});

test('near either end there is nothing more that way', function () {
    [$alice, $bob, $conversation] = contextConversation();
    $messages = contextMessages($conversation, $bob, 5);

    $response = $this->actingAs($alice)
        ->getJson(contextUrl($conversation, $messages[1]))
        ->assertOk();

    expect($response->json('data'))->toHaveCount(5);
    expect($response->json('meta.has_more_before'))->toBeFalse();
    expect($response->json('meta.has_more_after'))->toBeFalse();
    expect($response->json('meta.next_before_id'))->toBeNull();
    expect($response->json('meta.next_after_id'))->toBeNull();
});

test('defaults to twenty either side and never returns more than fifty', function () {
    [$alice, $bob, $conversation] = contextConversation();
    $messages = contextMessages($conversation, $bob, 121);

    $default = $this->actingAs($alice)->getJson(contextUrl($conversation, $messages[60]))->assertOk();
    expect($default->json('data'))->toHaveCount(41);

    $clamped = $this->actingAs($alice)->getJson(contextUrl($conversation, $messages[60], 'before=500&after=500'))->assertOk();
    expect($clamped->json('data'))->toHaveCount(101);
    expect($clamped->json('meta.has_more_before'))->toBeTrue();
    expect($clamped->json('meta.has_more_after'))->toBeTrue();
});

test('a count of zero still leaves a cursor that way', function () {
    [$alice, $bob, $conversation] = contextConversation();
    $messages = contextMessages($conversation, $bob, 5);

    $response = $this->actingAs($alice)
        ->getJson(contextUrl($conversation, $messages[2], 'before=0&after=0'))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('body')->all())->toBe(['m-3']);
    expect($response->json('meta.next_before_id'))->toBe($messages[2]->id);
    expect($response->json('meta.next_after_id'))->toBe($messages[2]->id);
});

test('a deleted message comes back as the deleted placeholder', function () {
    [$alice, $bob, $conversation] = contextConversation();
    $messages = contextMessages($conversation, $bob, 3);

    $this->actingAs($bob)->deleteJson("/api/messages/{$messages[1]->id}")->assertNoContent();

    $response = $this->actingAs($alice)->getJson(contextUrl($conversation, $messages[1]))->assertOk();

    $target = collect($response->json('data'))->firstWhere('id', $messages[1]->id);
    expect($target['body'])->toBe('');
    expect($target['deleted_at'])->not->toBeNull();
});

test('someone outside the conversation is refused', function () {
    [, $bob, $conversation] = contextConversation();
    $messages = contextMessages($conversation, $bob, 3);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->getJson(contextUrl($conversation, $messages[1]))->assertForbidden();
});

test('a message from another conversation is not found', function () {
    [$alice, $bob, $conversation] = contextConversation();
    [, , $elsewhere] = contextConversation();
    $foreign = contextMessages($elsewhere, $bob, 1)[0];

    $this->actingAs($alice)->getJson(contextUrl($conversation, $foreign))->assertNotFound();
});

test('a member who left can jump within their history but not past it', function () {
    [$alice, $bob, $conversation] = contextConversation();
    $before = contextMessages($conversation, $bob, 3, 'before');

    ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $alice->id)
        ->update(['left_at' => now(), 'left_at_message_id' => end($before)->id]);

    $after = contextMessages($conversation, $bob, 3, 'after');

    $inside = $this->actingAs($alice)->getJson(contextUrl($conversation, $before[1]))->assertOk();
    expect(collect($inside->json('data'))->pluck('body')->all())->toBe(['before-1', 'before-2', 'before-3']);
    expect($inside->json('meta.has_more_after'))->toBeFalse();

    $this->actingAs($alice)->getJson(contextUrl($conversation, $after[0]))->assertNotFound();
});

test('a deleted group is gone', function () {
    [$alice, $bob, $conversation] = contextConversation();
    $messages = contextMessages($conversation, $bob, 3);
    $conversation->forceFill(['deleted_at' => now()])->save();

    $this->actingAs($alice)->getJson(contextUrl($conversation, $messages[1]))->assertNotFound();
});

test('a malformed message id is not found rather than an error', function () {
    [$alice, , $conversation] = contextConversation();

    $this->actingAs($alice)
        ->getJson("/api/conversations/{$conversation->id}/messages/not-a-uuid/context")
        ->assertNotFound();
});

test('the visibility rule: participants only, up to their cutoff, never in a deleted group', function () {
    [$alice, $bob, $conversation] = contextConversation();
    $early = contextMessages($conversation, $bob, 2, 'early');

    ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $alice->id)
        ->update(['left_at' => now(), 'left_at_message_id' => end($early)->id]);

    contextMessages($conversation, $bob, 2, 'late');

    [$carol, , $deleted] = contextConversation();
    contextMessages($deleted, $carol, 1, 'deleted');
    $deleted->forceFill(['deleted_at' => now()])->save();

    $stranger = User::factory()->create();

    expect(Message::visibleTo($alice)->orderBy('id')->pluck('body')->all())->toBe(['early-1', 'early-2']);
    expect(Message::visibleTo($bob)->orderBy('id')->pluck('body')->all())->toBe(['early-1', 'early-2', 'late-1', 'late-2']);
    expect(Message::visibleTo($carol)->count())->toBe(0);
    expect(Message::visibleTo($stranger)->count())->toBe(0);
});
