<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
