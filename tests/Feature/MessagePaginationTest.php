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
function paginationConversation(): array
{
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);

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
 * Messages are created one at a time so their UUIDv7 ids are strictly
 * increasing, which is what the cursor relies on.
 *
 * @return array<int, Message>
 */
function seedMessages(Conversation $conversation, User $sender, int $count, string $prefix = 'm'): array
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

test('the first page returns the newest messages, oldest first', function () {
    [$alice, $bob, $conversation] = paginationConversation();
    seedMessages($conversation, $bob, 30);

    $response = $this->actingAs($alice)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=10")
        ->assertOk();

    $bodies = collect($response->json('data'))->pluck('body')->all();

    // Newest ten (m-21..m-30), rendered top-to-bottom.
    expect($bodies)->toBe([
        'm-21', 'm-22', 'm-23', 'm-24', 'm-25', 'm-26', 'm-27', 'm-28', 'm-29', 'm-30',
    ]);
    expect($response->json('meta.has_more'))->toBeTrue();
});

test('the cursor walks backwards through history without repeating or skipping a message', function () {
    [$alice, $bob, $conversation] = paginationConversation();
    seedMessages($conversation, $bob, 25);

    $seen = [];
    $before = null;

    do {
        $url = "/api/conversations/{$conversation->id}/messages?limit=10"
            .($before ? "&before_id={$before}" : '');

        $response = $this->actingAs($alice)->getJson($url)->assertOk();

        $seen = array_merge(collect($response->json('data'))->pluck('body')->all(), $seen);
        $before = $response->json('meta.next_before_id');
    } while ($response->json('meta.has_more'));

    expect($seen)->toHaveCount(25);
    expect(array_unique($seen))->toHaveCount(25);
    expect($seen)->toBe(collect(range(1, 25))->map(fn ($n) => 'm-'.$n)->all());
});

// The bug this whole scheme exists to prevent. Under `?page=N`, messages
// arriving between two requests shift every page boundary, so page 2 hands
// back rows page 1 already held — which rendered duplicates and threw the
// unread divider off. A cursor is anchored to a message, so it cannot drift.
test('messages arriving between page requests do not cause the next page to repeat rows', function () {
    [$alice, $bob, $conversation] = paginationConversation();
    seedMessages($conversation, $bob, 20);

    $first = $this->actingAs($alice)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=10")
        ->assertOk();

    $firstBodies = collect($first->json('data'))->pluck('body')->all();

    // Five new messages land before the client asks for older history.
    seedMessages($conversation, $bob, 5, 'late');

    $cursor = $first->json('meta.next_before_id');
    $second = $this->actingAs($alice)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=10&before_id={$cursor}")
        ->assertOk();

    $secondBodies = collect($second->json('data'))->pluck('body')->all();

    expect(array_intersect($firstBodies, $secondBodies))->toBeEmpty();
    expect($secondBodies)->toBe(['m-1', 'm-2', 'm-3', 'm-4', 'm-5', 'm-6', 'm-7', 'm-8', 'm-9', 'm-10']);
});

test('has_more is false and next_before_id is null once history is exhausted', function () {
    [$alice, $bob, $conversation] = paginationConversation();
    seedMessages($conversation, $bob, 3);

    $response = $this->actingAs($alice)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=10")
        ->assertOk();

    expect($response->json('meta.has_more'))->toBeFalse();
    expect($response->json('meta.next_before_id'))->toBeNull();
    expect($response->json('data'))->toHaveCount(3);
});

test('an empty conversation returns no messages and no cursor', function () {
    [$alice, , $conversation] = paginationConversation();

    $response = $this->actingAs($alice)
        ->getJson("/api/conversations/{$conversation->id}/messages")
        ->assertOk();

    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.has_more'))->toBeFalse();
    expect($response->json('meta.next_before_id'))->toBeNull();
});

test('the limit is clamped so a client cannot ask for the whole table', function () {
    [$alice, $bob, $conversation] = paginationConversation();
    seedMessages($conversation, $bob, 120);

    $response = $this->actingAs($alice)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=5000")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(100);
});

test('a member who left still sees history frozen at the moment they left when paginating', function () {
    [$alice, $bob, $conversation] = paginationConversation();
    $before = seedMessages($conversation, $bob, 5, 'before');

    ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $alice->id)
        ->update(['left_at' => now(), 'left_at_message_id' => end($before)->id]);

    seedMessages($conversation, $bob, 5, 'after');

    $response = $this->actingAs($alice)
        ->getJson("/api/conversations/{$conversation->id}/messages?limit=50")
        ->assertOk();

    $bodies = collect($response->json('data'))->pluck('body')->all();

    expect($bodies)->toHaveCount(5);
    expect($bodies)->each->toStartWith('before-');
});
