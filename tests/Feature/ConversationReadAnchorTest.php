<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * last_read_message_id is what unread_count is derived from. Exposing it lets
 * the client anchor its "new messages" divider on the exact message the
 * server counted from, rather than counting backwards from the end of the
 * list and having to reproduce the server's rules about which messages count.
 *
 * @return array{0: User, 1: User, 2: Conversation}
 */
function readAnchorConversation(): array
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

test('the conversation list exposes the viewer read anchor', function () {
    [$alice, $bob, $conversation] = readAnchorConversation();

    $read = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $bob->id,
    ]);
    Message::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $bob->id,
    ]);

    ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $alice->id)
        ->update(['last_read_message_id' => $read->id]);

    $response = $this->actingAs($alice)->getJson('/api/conversations')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $conversation->id);

    expect($row['last_read_message_id'])->toBe($read->id);
    // The anchor and the count must agree: two messages are newer than it.
    expect($row['unread_count'])->toBe(2);
});

test('the read anchor is null for a viewer who has never read the conversation', function () {
    [$alice, $bob, $conversation] = readAnchorConversation();

    Message::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $bob->id,
    ]);

    $response = $this->actingAs($alice)->getJson('/api/conversations')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $conversation->id);

    expect($row['last_read_message_id'])->toBeNull();
    expect($row['unread_count'])->toBe(2);
});

test('the read anchor advances when the conversation is marked as read', function () {
    [$alice, $bob, $conversation] = readAnchorConversation();

    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $bob->id,
    ]);

    $this->actingAs($alice)->postJson("/api/conversations/{$conversation->id}/read")->assertNoContent();

    $newest = Message::where('conversation_id', $conversation->id)->orderByDesc('id')->first();

    $response = $this->actingAs($alice)->getJson('/api/conversations')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $conversation->id);

    expect($row['last_read_message_id'])->toBe($newest->id);
    expect($row['unread_count'])->toBe(0);
});

test('a viewer who left the group gets a null read anchor, matching its zeroed unread count', function () {
    [$alice, $bob, $conversation] = readAnchorConversation();

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $bob->id,
    ]);

    ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $alice->id)
        ->update([
            'last_read_message_id' => $message->id,
            'left_at' => now(),
            'left_at_message_id' => $message->id,
        ]);

    $response = $this->actingAs($alice)->getJson('/api/conversations')->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $conversation->id);

    expect($row['last_read_message_id'])->toBeNull();
    expect($row['unread_count'])->toBe(0);
});
