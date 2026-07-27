<?php

use App\Events\MessageReactionUpdated;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function reactionConversation(): array
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
    ]);

    return [$alice, $bob, $conversation, $message];
}

test('a participant can react to a message', function () {
    [$alice, , , $message] = reactionConversation();

    $response = $this->actingAs($alice)->postJson("/api/messages/{$message->id}/reactions", [
        'reaction' => '👍',
    ]);

    $response->assertOk();
    expect($response->json('data.reactions'))->toHaveCount(1);
    expect($response->json('data.reactions.0.reaction'))->toBe('👍');
    expect($response->json('data.reactions.0.user.id'))->toBe($alice->id);
    // The response feeds a realtime broadcast the frontend renders directly,
    // so the message's other fields (like sender) must still be present.
    expect($response->json('data.sender.id'))->toBe($message->sender_user_id);

    $this->assertDatabaseHas('message_reactions', [
        'message_id' => $message->id,
        'user_id' => $alice->id,
        'reaction' => '👍',
    ]);
});

test('reacting with the same emoji twice does not create a duplicate row', function () {
    [$alice, , , $message] = reactionConversation();

    $this->actingAs($alice)->postJson("/api/messages/{$message->id}/reactions", ['reaction' => '👍'])->assertOk();
    $response = $this->actingAs($alice)->postJson("/api/messages/{$message->id}/reactions", ['reaction' => '👍']);

    $response->assertOk();
    expect($response->json('data.reactions'))->toHaveCount(1);
    expect(MessageReaction::where('message_id', $message->id)->count())->toBe(1);
});

test('a non-participant cannot react to a message', function () {
    [, , , $message] = reactionConversation();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->postJson("/api/messages/{$message->id}/reactions", ['reaction' => '👍'])
        ->assertForbidden();
});

test('reacting with an emoji outside the allowed set is rejected', function () {
    [$alice, , , $message] = reactionConversation();

    $this->actingAs($alice)
        ->postJson("/api/messages/{$message->id}/reactions", ['reaction' => '🍕'])
        ->assertUnprocessable();
});

test('removing a reaction broadcasts a message with sender still loaded', function () {
    Event::fake([MessageReactionUpdated::class]);
    [$alice, , , $message] = reactionConversation();

    $this->actingAs($alice)
        ->deleteJson("/api/messages/{$message->id}/reactions/".rawurlencode('👍'))
        ->assertNoContent();

    Event::assertDispatched(MessageReactionUpdated::class, function (MessageReactionUpdated $event) use ($message) {
        return $event->message->is($message) && $event->message->relationLoaded('sender');
    });
});

test('a participant can remove their own reaction', function () {
    [$alice, , , $message] = reactionConversation();

    $this->actingAs($alice)->postJson("/api/messages/{$message->id}/reactions", ['reaction' => '👍'])->assertOk();

    $this->actingAs($alice)
        ->deleteJson("/api/messages/{$message->id}/reactions/".rawurlencode('👍'))
        ->assertNoContent();

    $this->assertDatabaseMissing('message_reactions', [
        'message_id' => $message->id,
        'user_id' => $alice->id,
        'reaction' => '👍',
    ]);
});

test('removing a reaction that does not exist is a no-op', function () {
    [$alice, , , $message] = reactionConversation();

    $this->actingAs($alice)
        ->deleteJson("/api/messages/{$message->id}/reactions/".rawurlencode('👍'))
        ->assertNoContent();
});

test('a non-participant cannot remove a reaction', function () {
    [, , , $message] = reactionConversation();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->deleteJson("/api/messages/{$message->id}/reactions/".rawurlencode('👍'))
        ->assertForbidden();
});

test('multiple participants can react to the same message with different emoji', function () {
    [$alice, $bob, , $message] = reactionConversation();

    $this->actingAs($alice)->postJson("/api/messages/{$message->id}/reactions", ['reaction' => '👍'])->assertOk();
    $response = $this->actingAs($bob)->postJson("/api/messages/{$message->id}/reactions", ['reaction' => '❤️']);

    $response->assertOk();
    expect($response->json('data.reactions'))->toHaveCount(2);
});
