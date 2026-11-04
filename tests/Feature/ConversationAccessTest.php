<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

afterEach(function () {
    Redis::flushdb();
});

// Who may do what in a group, route by route. Three rules
// (ConversationPolicy): anyone with a place in it, members who left included,
// may view it; its current members may take part; its admins may manage it.
// Anyone else is refused (403), and a deleted group is gone for everyone
// (404).

/**
 * Ann (the admin), Ben and Cal in "Crew", Dee who has left it, and Sam, who
 * was never in it; a message from Ann from before Dee left.
 *
 * @return array{ann: User, ben: User, cal: User, dee: User, sam: User, group: Conversation, message: Message}
 */
function accessGroup(): array
{
    [$ann, $ben, $cal, $dee, $sam] = User::factory()->count(5)->create()->all();
    $group = Conversation::factory()->group('Crew')->create(['created_by_user_id' => $ann->id]);

    foreach ([[$ann, 'admin'], [$ben, 'participant'], [$cal, 'participant'], [$dee, 'participant']] as [$user, $role]) {
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);
    }

    $message = Message::factory()->create(['conversation_id' => $group->id, 'sender_user_id' => $ann->id, 'body' => 'hello crew']);
    app(ConversationParticipantService::class)->leave($group, $dee);

    return ['ann' => $ann, 'ben' => $ben, 'cal' => $cal, 'dee' => $dee, 'sam' => $sam, 'group' => $group, 'message' => $message];
}

/**
 * Each route, the rule it goes by, and the request, made as $as.
 *
 * @return array<string, array{0: 'view'|'participate'|'manage', 1: Closure(array<string, mixed>, User): TestResponse}>
 */
dataset('routes', [
    'open it' => ['view', fn (array $g, User $as) => test()->actingAs($as)->getJson("/api/conversations/{$g['group']->id}")],
    'read its history' => ['view', fn (array $g, User $as) => test()->actingAs($as)->getJson("/api/conversations/{$g['group']->id}/messages")],
    'jump to a message' => ['view', fn (array $g, User $as) => test()->actingAs($as)->getJson("/api/conversations/{$g['group']->id}/messages/{$g['message']->id}/context")],
    'see its shared media' => ['view', fn (array $g, User $as) => test()->actingAs($as)->getJson("/api/conversations/{$g['group']->id}/attachments?kind=media")],
    'search it' => ['view', fn (array $g, User $as) => test()->actingAs($as)->getJson("/api/messages/search?q=hello&conversation_id={$g['group']->id}")],
    'pin it' => ['view', fn (array $g, User $as) => test()->actingAs($as)->patchJson("/api/conversations/{$g['group']->id}/preferences", ['pinned' => true])],
    'see a message’s info' => ['view', fn (array $g, User $as) => test()->actingAs($as)->getJson("/api/messages/{$g['message']->id}/info")],
    'take a message off Saved' => ['view', fn (array $g, User $as) => test()->actingAs($as)->deleteJson("/api/messages/{$g['message']->id}/save")],

    'write' => ['participate', fn (array $g, User $as) => test()->actingAs($as)->postJson('/api/messages', ['conversation_id' => $g['group']->id, 'body' => 'hi'])],
    'type' => ['participate', fn (array $g, User $as) => test()->actingAs($as)->postJson("/api/conversations/{$g['group']->id}/typing")],
    'mark it read' => ['participate', fn (array $g, User $as) => test()->actingAs($as)->postJson("/api/conversations/{$g['group']->id}/read")],
    'see who’s read what' => ['participate', fn (array $g, User $as) => test()->actingAs($as)->getJson("/api/conversations/{$g['group']->id}/reads")],
    'see who’s in it now' => ['participate', fn (array $g, User $as) => test()->actingAs($as)->getJson("/api/conversations/{$g['group']->id}/participants")],
    'save a message' => ['participate', fn (array $g, User $as) => test()->actingAs($as)->postJson("/api/messages/{$g['message']->id}/save")],
    'react' => ['participate', fn (array $g, User $as) => test()->actingAs($as)->postJson("/api/messages/{$g['message']->id}/reactions", ['reaction' => '👍'])],
    'leave' => ['participate', fn (array $g, User $as) => test()->actingAs($as)->deleteJson("/api/conversations/{$g['group']->id}/participants/{$as->id}")],

    'rename it' => ['manage', fn (array $g, User $as) => test()->actingAs($as)->patchJson("/api/conversations/{$g['group']->id}", ['title' => 'Crew 2'])],
    'add someone' => ['manage', fn (array $g, User $as) => test()->actingAs($as)->postJson("/api/conversations/{$g['group']->id}/participants", ['user_id' => $g['sam']->id])],
    'make someone an admin' => ['manage', fn (array $g, User $as) => test()->actingAs($as)->patchJson("/api/conversations/{$g['group']->id}/participants/{$g['cal']->id}", ['role' => 'admin'])],
    'remove someone' => ['manage', fn (array $g, User $as) => test()->actingAs($as)->deleteJson("/api/conversations/{$g['group']->id}/participants/{$g['cal']->id}/kick")],
    'delete it' => ['manage', fn (array $g, User $as) => test()->actingAs($as)->deleteJson("/api/conversations/{$g['group']->id}")],
]);

test('each route lets in who its rule says, and refuses everyone else', function (string $rule, Closure $call) {
    $g = accessGroup();

    // Who each rule refuses, and who it lets in: the refusals first, as
    // they change nothing.
    $refused = match ($rule) {
        'view' => [$g['sam']],
        'participate' => [$g['sam'], $g['dee']],
        'manage' => [$g['sam'], $g['dee'], $g['ben']],
    };
    $allowed = match ($rule) {
        'view' => [$g['dee'], $g['ben']],
        'participate' => [$g['ben']],
        'manage' => [$g['ann']],
    };

    foreach ($refused as $user) {
        $call($g, $user)->assertForbidden();
    }
    foreach ($allowed as $user) {
        expect($call($g, $user)->status())->toBeIn([200, 201, 204]);
    }
})->with('routes');

test('a deleted group is gone for everyone, on every route', function (string $rule, Closure $call) {
    $g = accessGroup();
    $g['group']->delete();

    foreach ([$g['ann'], $g['ben'], $g['dee'], $g['sam']] as $user) {
        $call($g, $user)->assertNotFound();
    }
})->with('routes');

// Removing is for other people: an admin leaves instead, even with another
// admin to keep the group going.
test('an admin can’t remove themselves', function () {
    $g = accessGroup();
    app(ConversationParticipantService::class)->updateRole($g['group'], $g['ann'], $g['ben'], 'admin');

    $this->actingAs($g['ann'])->deleteJson("/api/conversations/{$g['group']->id}/participants/{$g['ann']->id}/kick")
        ->assertForbidden();

    expect(ConversationParticipant::where('user_id', $g['ann']->id)->sole()->left_at)->toBeNull();
});

test('a deleted group is out of every list and lookup, its rows kept', function () {
    $g = accessGroup();

    $g['group']->delete();

    expect(Conversation::find($g['group']->id))->toBeNull()
        ->and(Conversation::withTrashed()->find($g['group']->id)?->trashed())->toBeTrue()
        ->and($g['message']->fresh()->conversation?->id)->toBe($g['group']->id);
    expect(collect($this->actingAs($g['ben'])->getJson('/api/conversations')->json('data'))->pluck('id'))
        ->not->toContain($g['group']->id);
});
