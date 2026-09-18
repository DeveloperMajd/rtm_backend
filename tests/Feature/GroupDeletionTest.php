<?php

use App\Events\ConversationDeleted;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function conversationIds(User $user): array
{
    return test()->actingAs($user)->getJson('/api/conversations')->json('data.*.id');
}

test('an admin can delete a group, removing it for every member', function () {
    Event::fake([ConversationDeleted::class]);

    $admin = User::factory()->create();
    $member = User::factory()->create();
    $conversation = Conversation::factory()->group('Squad')->create(['created_by_user_id' => $admin->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member->id, 'role' => 'participant', 'joined_at' => now()]);

    $this->actingAs($admin)->deleteJson("/api/conversations/{$conversation->id}")->assertNoContent();

    expect($conversation->fresh()->deleted_at)->not->toBeNull();
    expect(conversationIds($admin))->not->toContain($conversation->id);
    expect(conversationIds($member))->not->toContain($conversation->id);
    Event::assertDispatched(ConversationDeleted::class);
});

test('a non-admin cannot delete a group', function () {
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $conversation = Conversation::factory()->group('Squad')->create(['created_by_user_id' => $admin->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member->id, 'role' => 'participant', 'joined_at' => now()]);

    $this->actingAs($member)->deleteJson("/api/conversations/{$conversation->id}")->assertForbidden();
    expect($conversation->fresh()->deleted_at)->toBeNull();
});

test('a deleted group cannot be viewed or messaged afterward', function () {
    $admin = User::factory()->create();
    $conversation = Conversation::factory()->group('Squad')->create(['created_by_user_id' => $admin->id]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);

    $this->actingAs($admin)->deleteJson("/api/conversations/{$conversation->id}")->assertNoContent();

    $this->actingAs($admin)->getJson("/api/conversations/{$conversation->id}")->assertNotFound();
    $this->actingAs($admin)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'hello?',
    ])->assertForbidden();
});

test('deleting a direct conversation is not supported', function () {
    // Reverted feature: a per-viewer "hide" broke re-opening a conversation
    // from the contacts list, since that flow depends on it staying in the
    // cached conversations list it was just filtered out of. See
    // ConversationPolicy::delete().
    $me = User::factory()->create();
    $other = User::factory()->create();
    $conversation = Conversation::factory()->create();
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $me->id, 'joined_at' => now()]);
    ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $other->id, 'joined_at' => now()]);

    $this->actingAs($me)->deleteJson("/api/conversations/{$conversation->id}")->assertForbidden();
});
