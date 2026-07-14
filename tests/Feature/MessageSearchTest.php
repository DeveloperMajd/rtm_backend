<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('search returns only messages from conversations the user participates in', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $outsider = User::factory()->create();

    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $bob->id, 'role' => 'participant', 'joined_at' => now()]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $bob->id, 'body' => 'lets meet at the coffee shop']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=coffee');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);

    $response = $this->actingAs($outsider)->getJson('/api/messages/search?q=coffee');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

test('search matches on word stems and ignores irrelevant messages', function () {
    $alice = User::factory()->create();
    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'I am running to the store']);
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'completely unrelated message']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=run');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.body'))->toBe('I am running to the store');
});

test('search handles free-text multi-word input without a syntax error', function () {
    $alice = User::factory()->create();
    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'the quick brown fox']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=quick fox');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

test('a direct conversation search result shows the other participant as the title', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $bob->id, 'role' => 'participant', 'joined_at' => now()]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $bob->id, 'body' => 'searchable keyword here']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=searchable');

    $response->assertOk();
    expect($response->json('data.0.conversation_title'))->toBe('Bob');
});

test('query shorter than 2 characters is rejected', function () {
    $alice = User::factory()->create();

    $this->actingAs($alice)->getJson('/api/messages/search?q=a')->assertUnprocessable();
});

test('stemmed matches are still found, preserving recall', function () {
    $alice = User::factory()->create();
    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);

    // "universe" and "university" both stem to 'univers' under the English
    // config, so a university-only message still surfaces as a fallback match.
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'I study at the university']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=universe');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

test('a literal word match outranks a match that only exists due to stemming', function () {
    $alice = User::factory()->create();
    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);

    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'I study at the university']);
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $alice->id, 'body' => 'look at that universe of stars']);

    $response = $this->actingAs($alice)->getJson('/api/messages/search?q=universe');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    // Both are found (recall), but the literal match ranks first (precision).
    expect($response->json('data.0.body'))->toBe('look at that universe of stars');
});
