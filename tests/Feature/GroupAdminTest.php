<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: User, 2: User, 3: Conversation}
 */
function threePersonGroup(): array
{
    $admin = User::factory()->create(['name' => 'Admin One']);
    $member = User::factory()->create(['name' => 'Member Two']);
    $other = User::factory()->create(['name' => 'Member Three']);

    $conversation = Conversation::factory()->group('Squad')->create(['created_by_user_id' => $admin->id]);

    foreach ([[$admin, 'admin'], [$member, 'participant'], [$other, 'participant']] as [$user, $role]) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    return [$admin, $member, $other, $conversation];
}

function participantRole(Conversation $conversation, User $user): ?string
{
    return ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $user->id)
        ->value('role');
}

function lastMessageOf(Conversation $conversation): Message
{
    return $conversation->messages()->latest('id')->firstOrFail();
}

test('creating a group emits a group_created system message', function () {
    $admin = User::factory()->create(['name' => 'Founder']);
    $friend = User::factory()->create();

    $response = $this->actingAs($admin)->postJson('/api/conversations', [
        'type' => 'group',
        'title' => 'New Group',
        'participant_ids' => [$friend->id],
    ])->assertCreated();

    $conversation = Conversation::findOrFail($response->json('data.id'));
    $message = lastMessageOf($conversation);

    expect($message->type)->toBe('system');
    expect($message->event_type)->toBe('group_created');
    expect($message->metadata['actor_id'])->toBe($admin->id);
    expect($conversation->fresh()->last_message_id)->toBe($message->id);
});

test('adding a participant emits a member_added system message', function () {
    [$admin, , , $conversation] = threePersonGroup();
    $newUser = User::factory()->create();

    $this->actingAs($admin)->postJson("/api/conversations/{$conversation->id}/participants", [
        'user_id' => $newUser->id,
    ])->assertCreated();

    $message = lastMessageOf($conversation);
    expect($message->event_type)->toBe('member_added');
    expect($message->metadata['target_id'])->toBe($newUser->id);
});

test('re-adding a previously kicked member reactivates their row', function () {
    [$admin, $member, , $conversation] = threePersonGroup();

    $this->actingAs($admin)->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}/kick")->assertOk();
    expect(ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $member->id)->value('left_at'))->not->toBeNull();

    $this->actingAs($admin)->postJson("/api/conversations/{$conversation->id}/participants", [
        'user_id' => $member->id,
    ])->assertCreated();

    $participant = ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $member->id)->first();
    expect($participant->left_at)->toBeNull();
    expect($participant->role)->toBe('participant');
});

test('an admin can promote another member to admin', function () {
    [$admin, $member, , $conversation] = threePersonGroup();

    $response = $this->actingAs($admin)
        ->patchJson("/api/conversations/{$conversation->id}/participants/{$member->id}", ['role' => 'admin']);

    $response->assertOk();
    expect(participantRole($conversation, $member))->toBe('admin');

    $message = lastMessageOf($conversation);
    expect($message->event_type)->toBe('member_promoted');
});

test('an admin can demote a co-admin back to participant', function () {
    [$admin, $member, , $conversation] = threePersonGroup();
    $this->actingAs($admin)->patchJson("/api/conversations/{$conversation->id}/participants/{$member->id}", ['role' => 'admin'])->assertOk();

    $response = $this->actingAs($admin)
        ->patchJson("/api/conversations/{$conversation->id}/participants/{$member->id}", ['role' => 'participant']);

    $response->assertOk();
    expect(participantRole($conversation, $member))->toBe('participant');

    $message = lastMessageOf($conversation);
    expect($message->event_type)->toBe('member_demoted');
});

test('demoting the last active admin is blocked', function () {
    [$admin, , , $conversation] = threePersonGroup();

    $this->actingAs($admin)
        ->patchJson("/api/conversations/{$conversation->id}/participants/{$admin->id}", ['role' => 'participant'])
        ->assertForbidden();

    expect(participantRole($conversation, $admin))->toBe('admin');
});

test('a non-admin cannot change anyone\'s role', function () {
    [, $member, $other, $conversation] = threePersonGroup();

    $this->actingAs($member)
        ->patchJson("/api/conversations/{$conversation->id}/participants/{$other->id}", ['role' => 'admin'])
        ->assertForbidden();
});

test('the sole admin cannot leave while other members remain', function () {
    [$admin, , , $conversation] = threePersonGroup();

    $response = $this->actingAs($admin)->deleteJson("/api/conversations/{$conversation->id}/participants/{$admin->id}");

    $response->assertStatus(422);
    expect(ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $admin->id)->value('left_at'))->toBeNull();
});

test('the sole admin can leave after promoting someone else', function () {
    [$admin, $member, , $conversation] = threePersonGroup();

    $this->actingAs($admin)->patchJson("/api/conversations/{$conversation->id}/participants/{$member->id}", ['role' => 'admin'])->assertOk();

    $this->actingAs($admin)
        ->deleteJson("/api/conversations/{$conversation->id}/participants/{$admin->id}")
        ->assertOk();

    $participant = ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $admin->id)->first();
    expect($participant->left_at)->not->toBeNull();

    $message = lastMessageOf($conversation);
    expect($message->event_type)->toBe('member_left');
});

test('a plain participant can leave a group at any time', function () {
    [, $member, , $conversation] = threePersonGroup();

    $this->actingAs($member)
        ->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}")
        ->assertOk();

    expect(ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $member->id)->value('left_at'))->not->toBeNull();
});

test('a lone member may leave even as the only admin', function () {
    $admin = User::factory()->create();
    $conversation = Conversation::factory()->group('Solo')->create(['created_by_user_id' => $admin->id]);
    ConversationParticipant::create([
        'conversation_id' => $conversation->id,
        'user_id' => $admin->id,
        'role' => 'admin',
        'joined_at' => now(),
    ]);

    $this->actingAs($admin)
        ->deleteJson("/api/conversations/{$conversation->id}/participants/{$admin->id}")
        ->assertOk();
});

test('with two admins, one can kick the other, leaving one admin', function () {
    [$admin, $member, , $conversation] = threePersonGroup();
    $this->actingAs($admin)->patchJson("/api/conversations/{$conversation->id}/participants/{$member->id}", ['role' => 'admin'])->assertOk();

    $this->actingAs($admin)
        ->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}/kick")
        ->assertOk();

    expect(ConversationParticipant::where('conversation_id', $conversation->id)->where('role', 'admin')->whereNull('left_at')->count())->toBe(1);
});

test('kicking a participant emits a member_removed system message', function () {
    [$admin, $member, , $conversation] = threePersonGroup();

    $this->actingAs($admin)->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}/kick")->assertOk();

    $message = lastMessageOf($conversation);
    expect($message->event_type)->toBe('member_removed');
    expect($message->metadata['target_id'])->toBe($member->id);
});

test('an admin can rename the group, emitting a group_renamed system message', function () {
    [$admin, , , $conversation] = threePersonGroup();

    $this->actingAs($admin)
        ->patchJson("/api/conversations/{$conversation->id}", ['title' => 'Renamed Squad'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed Squad');

    $message = lastMessageOf($conversation);
    expect($message->event_type)->toBe('group_renamed');
    expect($message->metadata['new_title'])->toBe('Renamed Squad');
    expect($message->metadata['old_title'])->toBe('Squad');
});

test('a non-admin cannot rename the group', function () {
    [, $member, , $conversation] = threePersonGroup();

    $this->actingAs($member)
        ->patchJson("/api/conversations/{$conversation->id}", ['title' => 'Nope'])
        ->assertForbidden();
});
