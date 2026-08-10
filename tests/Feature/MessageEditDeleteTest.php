<?php

use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function editDeleteConversation(): array
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

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $bob->id,
        'body' => 'original body',
    ]);

    return [$alice, $bob, $conversation, $message];
}

test('the sender can edit their own message', function () {
    Event::fake([MessageUpdated::class]);
    [, $bob, , $message] = editDeleteConversation();

    $response = $this->actingAs($bob)->patchJson("/api/messages/{$message->id}", [
        'body' => 'edited body',
    ]);

    $response->assertOk();
    expect($response->json('data.body'))->toBe('edited body');
    expect($response->json('data.edited_at'))->not->toBeNull();

    $message->refresh();
    expect($message->body)->toBe('edited body');
    expect($message->edited_at)->not->toBeNull();

    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event) => $event->message->is($message));
});

test('a non-sender participant cannot edit a message', function () {
    [$alice, , , $message] = editDeleteConversation();

    $this->actingAs($alice)
        ->patchJson("/api/messages/{$message->id}", ['body' => 'edited body'])
        ->assertForbidden();

    expect($message->fresh()->body)->toBe('original body');
});

test('editing an already-deleted message is rejected', function () {
    [, $bob, , $message] = editDeleteConversation();

    $this->actingAs($bob)->deleteJson("/api/messages/{$message->id}")->assertNoContent();

    $this->actingAs($bob)
        ->patchJson("/api/messages/{$message->id}", ['body' => 'edited body'])
        ->assertUnprocessable();
});

test('the sender can delete their own message', function () {
    Event::fake([MessageUpdated::class]);
    [, $bob, , $message] = editDeleteConversation();

    $this->actingAs($bob)->deleteJson("/api/messages/{$message->id}")->assertNoContent();

    $message->refresh();
    expect($message->body)->toBe('');
    expect($message->deleted_at)->not->toBeNull();

    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event) => $event->message->is($message));
});

test('a non-sender participant cannot delete a message', function () {
    [$alice, , , $message] = editDeleteConversation();

    $this->actingAs($alice)
        ->deleteJson("/api/messages/{$message->id}")
        ->assertForbidden();

    expect($message->fresh()->deleted_at)->toBeNull();
});

test('deleting an already-deleted message is a no-op', function () {
    Event::fake([MessageUpdated::class]);
    [, $bob, , $message] = editDeleteConversation();

    $this->actingAs($bob)->deleteJson("/api/messages/{$message->id}")->assertNoContent();
    Event::assertDispatchedTimes(MessageUpdated::class, 1);

    $this->actingAs($bob)->deleteJson("/api/messages/{$message->id}")->assertNoContent();
    Event::assertDispatchedTimes(MessageUpdated::class, 1);
});

test('a message can be created as a reply to another message in the same conversation', function () {
    [$alice, , $conversation, $message] = editDeleteConversation();

    $response = $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'a reply',
        'reply_to_message_id' => $message->id,
    ]);

    $response->assertCreated();
    expect($response->json('data.reply_to.id'))->toBe($message->id);
    expect($response->json('data.reply_to.body'))->toBe('original body');
});

test('replying to a message from a different conversation is rejected', function () {
    [$alice, , $conversation] = editDeleteConversation();

    $otherConversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
    $otherMessage = Message::factory()->create([
        'conversation_id' => $otherConversation->id,
        'sender_user_id' => $alice->id,
    ]);

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'a reply',
        'reply_to_message_id' => $otherMessage->id,
    ])->assertUnprocessable();
});
