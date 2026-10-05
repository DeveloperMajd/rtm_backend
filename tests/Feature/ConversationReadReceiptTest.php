<?php

use App\Events\ConversationRead;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function readReceiptConversation(): array
{
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);

    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $alice->id,
        'role' => 'admin',
        'joined_at' => now(),
    ]);

    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $bob->id,
        'role' => 'participant',
        'joined_at' => now(),
    ]);

    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $bob->id,
    ]);

    return [$alice, $bob, $conversation];
}

test('unread count reflects messages sent by others since last read', function () {
    [$alice, , $conversation] = readReceiptConversation();

    $response = $this->actingAs($alice)->getJson('/api/conversations');

    $response->assertOk();
    $data = collect($response->json('data'))->firstWhere('id', $conversation->id);
    expect($data['unread_count'])->toBe(3);
});

test('marking a conversation as read resets unread count', function () {
    [$alice, , $conversation] = readReceiptConversation();

    $this->actingAs($alice)
        ->postJson("/api/conversations/{$conversation->id}/read")
        ->assertNoContent();

    $participant = ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $alice->id)
        ->first();

    expect($participant->last_read_message_id)->not->toBeNull();
    expect($participant->last_read_at)->not->toBeNull();

    $response = $this->actingAs($alice)->getJson('/api/conversations');
    $data = collect($response->json('data'))->firstWhere('id', $conversation->id);
    expect($data['unread_count'])->toBe(0);
});

test('marking as read does not count messages sent after the read receipt', function () {
    [$alice, $bob, $conversation] = readReceiptConversation();

    $this->actingAs($alice)->postJson("/api/conversations/{$conversation->id}/read")->assertNoContent();

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $bob->id,
    ]);

    $response = $this->actingAs($alice)->getJson('/api/conversations');
    $data = collect($response->json('data'))->firstWhere('id', $conversation->id);
    expect($data['unread_count'])->toBe(1);
});

test('a non-participant cannot mark a conversation as read', function () {
    [, , $conversation] = readReceiptConversation();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->postJson("/api/conversations/{$conversation->id}/read")
        ->assertForbidden();
});

function readPointerOf(Conversation $conversation, User $user): ?string
{
    return ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $user->id)
        ->value('last_read_message_id');
}

/**
 * @return array<int, Message>
 */
function messagesOf(Conversation $conversation): array
{
    return $conversation->messages()->orderBy('id')->get()->all();
}

test('reading up to a given message moves the pointer exactly there', function () {
    [$alice, , $conversation] = readReceiptConversation();
    [$first, $second] = messagesOf($conversation);

    $this->actingAs($alice)
        ->postJson("/api/conversations/{$conversation->id}/read", ['message_id' => $second->id])
        ->assertNoContent();

    expect(readPointerOf($conversation, $alice))->toBe($second->id);

    $data = collect($this->actingAs($alice)->getJson('/api/conversations')->json('data'))
        ->firstWhere('id', $conversation->id);
    expect($data['unread_count'])->toBe(1);
});

// A slower tab, or a retry arriving late, reports less than the pointer
// already records; taking it at its word would un-read messages.
test('the pointer never moves backwards', function () {
    [$alice, , $conversation] = readReceiptConversation();
    [$first, , $third] = messagesOf($conversation);

    $this->actingAs($alice)->postJson("/api/conversations/{$conversation->id}/read", ['message_id' => $third->id])->assertNoContent();
    $this->actingAs($alice)->postJson("/api/conversations/{$conversation->id}/read", ['message_id' => $first->id])->assertNoContent();

    expect(readPointerOf($conversation, $alice))->toBe($third->id);
});

test('the conversation hears about it only when the pointer actually moves', function () {
    Event::fake([ConversationRead::class]);
    [$alice, , $conversation] = readReceiptConversation();
    [$first, , $third] = messagesOf($conversation);

    $this->actingAs($alice)->postJson("/api/conversations/{$conversation->id}/read", ['message_id' => $third->id]);
    $this->actingAs($alice)->postJson("/api/conversations/{$conversation->id}/read", ['message_id' => $first->id]);
    $this->actingAs($alice)->postJson("/api/conversations/{$conversation->id}/read");

    Event::assertDispatchedTimes(ConversationRead::class, 1);
    Event::assertDispatched(ConversationRead::class, fn (ConversationRead $event) => $event->userId === $alice->id
        && $event->lastReadMessageId === $third->id
        && $event->broadcastOn()[0]->name === "private-conversation.{$conversation->id}");
});

test('a message from somewhere else, or not a message id at all, is refused', function () {
    [$alice, $bob, $conversation] = readReceiptConversation();
    [, , $elsewhere] = readReceiptConversation();
    $foreign = messagesOf($elsewhere)[0];

    $this->actingAs($alice)
        ->postJson("/api/conversations/{$conversation->id}/read", ['message_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message_id');
    $this->actingAs($alice)
        ->postJson("/api/conversations/{$conversation->id}/read", ['message_id' => 'not-a-uuid'])
        ->assertUnprocessable();

    expect(readPointerOf($conversation, $alice))->toBeNull();
});

// D3 in the Phase 2 audit, for reading: a member who left could move their
// pointer past the moment they left.
test('a member who left cannot move their pointer on', function () {
    [$alice, , $conversation] = readReceiptConversation();
    [$first] = messagesOf($conversation);

    ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $alice->id)
        ->update(['left_at' => now(), 'left_at_message_id' => $first->id]);

    $this->actingAs($alice)->postJson("/api/conversations/{$conversation->id}/read")->assertForbidden();

    expect(readPointerOf($conversation, $alice))->toBeNull();
});

test('a deleted group cannot be marked as read', function () {
    [$alice, , $conversation] = readReceiptConversation();
    $conversation->forceFill(['deleted_at' => now()])->save();

    $this->actingAs($alice)->postJson("/api/conversations/{$conversation->id}/read")->assertNotFound();
});

test('an empty conversation is read already', function () {
    $alice = User::factory()->create();
    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);

    $this->actingAs($alice)->postJson("/api/conversations/{$conversation->id}/read")->assertNoContent();

    expect(readPointerOf($conversation, $alice))->toBeNull();
});

test('members can see how far everyone still in the conversation has read', function () {
    [$alice, $bob, $conversation] = readReceiptConversation();
    [, $second] = messagesOf($conversation);
    $carol = User::factory()->create();
    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $carol->id,
        'role' => 'participant',
        'joined_at' => now(),
        'left_at' => now(),
        'left_at_message_id' => $second->id,
        'last_read_message_id' => $second->id,
    ]);

    $this->actingAs($bob)->postJson("/api/conversations/{$conversation->id}/read", ['message_id' => $second->id]);

    $reads = collect($this->actingAs($alice)->getJson("/api/conversations/{$conversation->id}/reads")->assertOk()->json('data'))
        ->keyBy('user_id');

    expect($reads->keys()->sort()->values()->all())->toBe(collect([$alice->id, $bob->id])->sort()->values()->all());
    expect($reads[$bob->id]['last_read_message_id'])->toBe($second->id);
    expect($reads[$bob->id]['last_read_at'])->not->toBeNull();
    expect($reads[$alice->id]['last_read_message_id'])->toBeNull();
});

test('only current members can see how far others have read', function () {
    [$alice, , $conversation] = readReceiptConversation();
    [$first] = messagesOf($conversation);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->getJson("/api/conversations/{$conversation->id}/reads")->assertForbidden();

    ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $alice->id)
        ->update(['left_at' => now(), 'left_at_message_id' => $first->id]);
    $this->actingAs($alice)->getJson("/api/conversations/{$conversation->id}/reads")->assertForbidden();

    $conversation->forceFill(['deleted_at' => now()])->save();
    $this->actingAs($alice)->getJson("/api/conversations/{$conversation->id}/reads")->assertNotFound();
});
