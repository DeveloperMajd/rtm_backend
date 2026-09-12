<?php

use App\Events\ConversationParticipantsUpdated;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function groupConversation(): array
{
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $conversation = Conversation::factory()->group('Test Group')->create(['created_by_user_id' => $admin->id]);

    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $admin->id,
        'role' => 'admin',
        'joined_at' => now(),
    ]);

    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $member->id,
        'role' => 'participant',
        'joined_at' => now(),
    ]);

    return [$admin, $member, $conversation];
}

test('an admin can add a participant to a group conversation', function () {
    [$admin, , $conversation] = groupConversation();
    $newUser = User::factory()->create();

    $response = $this->actingAs($admin)->postJson("/api/conversations/{$conversation->id}/participants", [
        'user_id' => $newUser->id,
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('conversation_participants', [
        'conversation_id' => $conversation->id,
        'user_id' => $newUser->id,
        'role' => 'participant',
    ]);
});

test('adding the same participant twice does not create a duplicate row', function () {
    [$admin, , $conversation] = groupConversation();
    $newUser = User::factory()->create();

    $this->actingAs($admin)->postJson("/api/conversations/{$conversation->id}/participants", ['user_id' => $newUser->id])->assertCreated();
    $this->actingAs($admin)->postJson("/api/conversations/{$conversation->id}/participants", ['user_id' => $newUser->id])->assertCreated();

    expect(
        ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $newUser->id)->count(),
    )->toBe(1);
});

test('a non-admin participant cannot add a participant', function () {
    [, $member, $conversation] = groupConversation();
    $newUser = User::factory()->create();

    $this->actingAs($member)
        ->postJson("/api/conversations/{$conversation->id}/participants", ['user_id' => $newUser->id])
        ->assertForbidden();
});

test('a non-participant cannot add a participant', function () {
    [, , $conversation] = groupConversation();
    $outsider = User::factory()->create();
    $newUser = User::factory()->create();

    $this->actingAs($outsider)
        ->postJson("/api/conversations/{$conversation->id}/participants", ['user_id' => $newUser->id])
        ->assertForbidden();
});

test('participants cannot be added to a direct conversation', function () {
    $admin = User::factory()->create();
    $conversation = Conversation::factory()->create(['type' => 'direct', 'created_by_user_id' => $admin->id]);

    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $admin->id,
        'role' => 'admin',
        'joined_at' => now(),
    ]);

    $newUser = User::factory()->create();

    $this->actingAs($admin)
        ->postJson("/api/conversations/{$conversation->id}/participants", ['user_id' => $newUser->id])
        ->assertForbidden();
});

test('an admin can kick another participant, keeping their row read-only', function () {
    [$admin, $member, $conversation] = groupConversation();

    $this->actingAs($admin)
        ->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}/kick")
        ->assertOk();

    // The row stays (WhatsApp-style: the group stays on their list, greyed
    // out) instead of being deleted — only `left_at` gets set.
    $participant = ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $member->id)
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->left_at)->not->toBeNull();
});

test('an admin cannot kick themselves', function () {
    [$admin, , $conversation] = groupConversation();

    $this->actingAs($admin)
        ->deleteJson("/api/conversations/{$conversation->id}/participants/{$admin->id}/kick")
        ->assertForbidden();

    $this->assertDatabaseHas('conversation_participants', [
        'conversation_id' => $conversation->id,
        'user_id' => $admin->id,
    ]);
});

test('a non-admin participant cannot kick another participant', function () {
    [$admin, $member, $conversation] = groupConversation();

    $this->actingAs($member)
        ->deleteJson("/api/conversations/{$conversation->id}/participants/{$admin->id}/kick")
        ->assertForbidden();
});

test('adding a participant broadcasts to the conversation and the new participant', function () {
    Event::fake([ConversationParticipantsUpdated::class]);
    [$admin, , $conversation] = groupConversation();
    $newUser = User::factory()->create();

    $this->actingAs($admin)->postJson("/api/conversations/{$conversation->id}/participants", ['user_id' => $newUser->id])->assertCreated();

    Event::assertDispatched(
        ConversationParticipantsUpdated::class,
        fn (ConversationParticipantsUpdated $event) => $event->conversation->is($conversation) && $event->target->is($newUser),
    );
});

test('the existing self-removal endpoint still only allows removing yourself', function () {
    [, $member, $conversation] = groupConversation();
    $other = User::factory()->create();

    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $other->id,
        'role' => 'participant',
        'joined_at' => now(),
    ]);

    $this->actingAs($member)
        ->deleteJson("/api/conversations/{$conversation->id}/participants/{$other->id}")
        ->assertForbidden();

    $this->actingAs($member)
        ->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}")
        ->assertOk();
});
