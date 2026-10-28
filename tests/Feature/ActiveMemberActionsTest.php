<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Services\ConversationParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * A group with an admin and a member, and one message from each.
 *
 * @return array{admin: User, member: User, group: Conversation, own: Message, others: Message}
 */
function activeMemberGroup(): array
{
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $group = Conversation::factory()->group('Team')->create(['created_by_user_id' => $admin->id]);

    foreach ([[$admin, 'admin'], [$member, 'participant']] as [$user, $role]) {
        ConversationParticipant::create([
            'conversation_id' => $group->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    return [
        'admin' => $admin,
        'member' => $member,
        'group' => $group,
        'own' => Message::factory()->create(['conversation_id' => $group->id, 'sender_user_id' => $member->id, 'body' => 'mine']),
        'others' => Message::factory()->create(['conversation_id' => $group->id, 'sender_user_id' => $admin->id, 'body' => 'theirs']),
    ];
}

/**
 * Each thing the member might try, on the message it applies to.
 *
 * @return array<string, array{0: Closure(array<string, mixed>): TestResponse}>
 */
dataset('member actions', [
    'edit their message' => [fn (array $g): TestResponse => test()->actingAs($g['member'])
        ->patchJson("/api/messages/{$g['own']->id}", ['body' => 'rewritten'])],
    'delete their message' => [fn (array $g): TestResponse => test()->actingAs($g['member'])
        ->deleteJson("/api/messages/{$g['own']->id}")],
    'react' => [fn (array $g): TestResponse => test()->actingAs($g['member'])
        ->postJson("/api/messages/{$g['others']->id}/reactions", ['reaction' => '👍'])],
    'take back a reaction' => [fn (array $g): TestResponse => test()->actingAs($g['member'])
        ->deleteJson("/api/messages/{$g['others']->id}/reactions/".rawurlencode('👍'))],
]);

/**
 * What happened to the member, or the group, before they try.
 *
 * @return array<string, array{0: Closure(array<string, mixed>): void, 1: int}>
 */
dataset('no longer taking part', [
    'they left' => [fn (array $g) => app(ConversationParticipantService::class)->leave($g['group'], $g['member']), 403],
    'they were removed' => [fn (array $g) => app(ConversationParticipantService::class)->kickParticipant($g['group'], $g['admin'], $g['member']), 403],
    // Gone for everyone, as when its history is read.
    'the group was deleted' => [fn (array $g) => $g['group']->forceFill(['deleted_at' => now()])->save(), 404],
]);

test('an active member can do each of these', function (Closure $act) {
    $g = activeMemberGroup();
    MessageReaction::create(['message_id' => $g['others']->id, 'user_id' => $g['member']->id, 'reaction' => '👍']);

    expect($act($g)->status())->toBeIn([200, 204]);
})->with('member actions');

test('someone no longer taking part can’t, and nothing changes', function (Closure $act, Closure $situation, int $status) {
    $g = activeMemberGroup();
    // Theirs from before, to take back.
    MessageReaction::create(['message_id' => $g['others']->id, 'user_id' => $g['member']->id, 'reaction' => '👍']);
    $situation($g);

    $act($g)->assertStatus($status);

    expect($g['own']->fresh())
        ->body->toBe('mine')
        ->edited_at->toBeNull()
        ->deleted_at->toBeNull();
    expect(MessageReaction::where('message_id', $g['others']->id)->pluck('user_id')->all())->toBe([$g['member']->id]);
})->with('member actions')->with('no longer taking part');

test('a member added back can take part again', function () {
    $g = activeMemberGroup();
    app(ConversationParticipantService::class)->leave($g['group'], $g['member']);
    app(ConversationParticipantService::class)->addParticipant($g['group'], $g['admin'], $g['member']);

    $this->actingAs($g['member'])
        ->postJson("/api/messages/{$g['others']->id}/reactions", ['reaction' => '👍'])
        ->assertOk();
});

test('a group event line and a deleted message take no reactions', function () {
    $g = activeMemberGroup();
    $event = Message::factory()->create([
        'conversation_id' => $g['group']->id,
        'sender_user_id' => null,
        'type' => 'system',
        'event_type' => 'member_added',
    ]);
    $g['others']->forceFill(['body' => '', 'deleted_at' => now()])->save();

    foreach ([$event, $g['others']] as $message) {
        $this->actingAs($g['member'])
            ->postJson("/api/messages/{$message->id}/reactions", ['reaction' => '👍'])
            ->assertForbidden();
    }

    expect(MessageReaction::count())->toBe(0);
});

test('someone else’s message can’t be edited or deleted', function () {
    $g = activeMemberGroup();

    $this->actingAs($g['member'])->patchJson("/api/messages/{$g['others']->id}", ['body' => 'mine now'])->assertForbidden();
    $this->actingAs($g['member'])->deleteJson("/api/messages/{$g['others']->id}")->assertForbidden();

    expect($g['others']->fresh())->body->toBe('theirs')->deleted_at->toBeNull();
});
