<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lastMessageConversation(): array
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

    return [$alice, $bob, $conversation];
}

test('sending a message denormalizes last_message_id onto the conversation', function () {
    [$alice, , $conversation] = lastMessageConversation();

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'first message',
    ])->assertCreated();

    $second = $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'second message',
    ])->assertCreated();

    expect($conversation->fresh()->last_message_id)->toBe($second->json('data.id'));
});

test('the conversation list surfaces the latest message body and sender', function () {
    [$alice, $bob, $conversation] = lastMessageConversation();

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'older message',
    ])->assertCreated();

    $this->actingAs($bob)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'newest message',
    ])->assertCreated();

    $response = $this->actingAs($alice)->getJson('/api/conversations');

    $data = collect($response->json('data'))->firstWhere('id', $conversation->id);
    expect($data['latest_message']['body'])->toBe('newest message');
    expect($data['latest_message']['sender_name'])->toBe($bob->name);
});
