<?php

use App\Events\ConversationPreferencesUpdated;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: User, 2: Conversation}
 */
function preferencesConversation(string $title = 'Team'): array
{
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $conversation = Conversation::factory()->group($title)->create(['created_by_user_id' => $alice->id]);

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

function preferencesOf(Conversation $conversation, User $user): ConversationParticipant
{
    return ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $user->id)->firstOrFail();
}

function preferencesUrl(Conversation $conversation): string
{
    return "/api/conversations/{$conversation->id}/preferences";
}

test('pins, mutes and archives a conversation for the viewer alone, and undoes each', function () {
    [$alice, $bob, $conversation] = preferencesConversation();

    $response = $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['pinned' => true, 'muted' => true])->assertOk();
    expect($response->json('data.pinned_at'))->not->toBeNull();
    expect($response->json('data.muted_at'))->not->toBeNull();
    expect($response->json('data.archived_at'))->toBeNull();

    expect(preferencesOf($conversation, $bob)->pinned_at)->toBeNull();

    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['pinned' => false, 'muted' => false])->assertOk();
    expect(preferencesOf($conversation, $alice)->only(['pinned_at', 'muted_at']))->toBe(['pinned_at' => null, 'muted_at' => null]);
});

test('the list carries the viewer’s own pin, mute and archive', function () {
    [$alice, $bob, $conversation] = preferencesConversation();
    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['muted' => true])->assertOk();

    $mine = collect($this->actingAs($alice)->getJson('/api/conversations')->json('data'))->firstWhere('id', $conversation->id);
    $theirs = collect($this->actingAs($bob)->getJson('/api/conversations')->json('data'))->firstWhere('id', $conversation->id);

    expect($mine['muted_at'])->not->toBeNull();
    expect($mine['pinned_at'])->toBeNull();
    expect($mine['archived_at'])->toBeNull();
    expect($theirs['muted_at'])->toBeNull();
});

test('archiving unpins; pinning unarchives; switching on again keeps the first time', function () {
    [$alice, , $conversation] = preferencesConversation();

    Carbon::setTestNow('2026-10-01 09:00:00');
    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['pinned' => true]);
    Carbon::setTestNow('2026-10-01 10:00:00');
    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['pinned' => true]);
    expect(preferencesOf($conversation, $alice)->pinned_at->toDateTimeString())->toBe('2026-10-01 09:00:00');

    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['archived' => true]);
    expect(preferencesOf($conversation, $alice)->pinned_at)->toBeNull();
    expect(preferencesOf($conversation, $alice)->archived_at)->not->toBeNull();

    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['pinned' => true]);
    expect(preferencesOf($conversation, $alice)->archived_at)->toBeNull();
    expect(preferencesOf($conversation, $alice)->pinned_at)->not->toBeNull();

    Carbon::setTestNow();
});

test('asks for at least one of the three, as true or false', function () {
    [$alice, , $conversation] = preferencesConversation();

    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), [])->assertUnprocessable();
    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['pinned' => 'sometimes'])->assertUnprocessable();
});

test('only people in the conversation — including someone who left — and not in a deleted group', function () {
    [$alice, $bob, $conversation] = preferencesConversation();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->patchJson(preferencesUrl($conversation), ['archived' => true])->assertForbidden();

    ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $bob->id)->update(['left_at' => now()]);
    $this->actingAs($bob)->patchJson(preferencesUrl($conversation), ['archived' => true])->assertOk();

    $conversation->forceFill(['deleted_at' => now()])->save();
    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['archived' => true])->assertNotFound();
});

test('tells the viewer’s other tabs, on their own channel, only when something changed', function () {
    Event::fake([ConversationPreferencesUpdated::class]);
    [$alice, , $conversation] = preferencesConversation();

    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['muted' => true]);
    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['muted' => true]);

    Event::assertDispatchedTimes(ConversationPreferencesUpdated::class, 1);
    Event::assertDispatched(ConversationPreferencesUpdated::class, fn (ConversationPreferencesUpdated $event) => $event->conversationId === $conversation->id
        && $event->mutedAt !== null
        && $event->broadcastOn()[0]->name === "private-App.Models.User.{$alice->id}");
});

test('the list puts pinned conversations first, then the most recently active', function () {
    [$alice, $bob, $quiet] = preferencesConversation('Quiet');
    [, , $busy] = preferencesConversation('Busy');
    [, , $pinned] = preferencesConversation('Pinned');
    foreach ([$busy, $pinned] as $conversation) {
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $alice->id, 'role' => 'participant', 'joined_at' => now()]);
    }

    $quiet->forceFill(['last_message_at' => now()->subDays(3)])->save();
    $pinned->forceFill(['last_message_at' => now()->subDays(5)])->save();
    $busy->forceFill(['last_message_at' => now()->subHour()])->save();
    $this->actingAs($alice)->patchJson(preferencesUrl($pinned), ['pinned' => true]);

    $titles = collect($this->actingAs($alice)->getJson('/api/conversations')->json('data'))->pluck('title')->all();

    expect($titles)->toBe(['Pinned', 'Busy', 'Quiet']);
});

// Ordering by the group's real activity would give away that it carried
// on without them, which nothing else the list shows does.
test('a group the viewer left is ordered by when they left, not by what happened since', function () {
    [$alice, , $left] = preferencesConversation('Left');
    [, , $recent] = preferencesConversation('Recent');
    ConversationParticipant::create(['conversation_id' => $recent->id, 'user_id' => $alice->id, 'role' => 'participant', 'joined_at' => now()]);

    ConversationParticipant::where('conversation_id', $left->id)->where('user_id', $alice->id)->update(['left_at' => now()->subDays(2)]);
    $left->forceFill(['last_message_at' => now()])->save();
    $recent->forceFill(['last_message_at' => now()->subDay()])->save();

    $titles = collect($this->actingAs($alice)->getJson('/api/conversations')->json('data'))->pluck('title')->all();

    expect($titles)->toBe(['Recent', 'Left']);
});

test('a new message brings an archived conversation back for the others, unless they muted it', function () {
    [$alice, $bob, $conversation] = preferencesConversation();
    $carol = User::factory()->create();
    $dan = User::factory()->create();
    foreach ([$carol, $dan] as $user) {
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);
    }

    $this->actingAs($alice)->patchJson(preferencesUrl($conversation), ['archived' => true]);
    $this->actingAs($bob)->patchJson(preferencesUrl($conversation), ['archived' => true]);
    $this->actingAs($carol)->patchJson(preferencesUrl($conversation), ['archived' => true, 'muted' => true]);
    $this->actingAs($dan)->patchJson(preferencesUrl($conversation), ['archived' => true]);
    ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $dan->id)->update(['left_at' => now()]);

    $this->actingAs($alice)->postJson('/api/messages', ['conversation_id' => $conversation->id, 'body' => 'anyone?'])->assertCreated();

    expect(preferencesOf($conversation, $bob)->archived_at)->toBeNull();
    expect(preferencesOf($conversation, $carol)->archived_at)->not->toBeNull();
    expect(preferencesOf($conversation, $dan)->archived_at)->not->toBeNull();
    // The sender archived it themselves and is still looking at it there.
    expect(preferencesOf($conversation, $alice)->archived_at)->not->toBeNull();
});
