<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A direct conversation between the two. */
function bioDirect(User $one, User $other): Conversation
{
    $conversation = Conversation::factory()->create(['created_by_user_id' => $one->id]);
    foreach ([$one, $other] as $user) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'participant',
            'joined_at' => now(),
        ]);
    }

    return $conversation;
}

// Not a contact of theirs: sharing the conversation is enough.
test('a direct conversation carries the other person’s bio, not the viewer’s', function () {
    $viewer = User::factory()->create(['bio' => 'Mine, not for this field.']);
    $sam = User::factory()->create(['bio' => 'Backend by day, WebSockets by night.']);
    $conversation = bioDirect($viewer, $sam);

    $this->actingAs($viewer)->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.other_participant.bio', 'Backend by day, WebSockets by night.');

    $this->actingAs($viewer)->getJson("/api/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.other_participant.bio', 'Backend by day, WebSockets by night.');
});

test('the bio is null for someone who hasn’t written one', function () {
    $viewer = User::factory()->create();
    $sam = User::factory()->create(['bio' => null]);
    bioDirect($viewer, $sam);

    $person = $this->actingAs($viewer)->getJson('/api/conversations')->assertOk()->json('data.0.other_participant');

    expect($person)->toHaveKey('bio')->and($person['bio'])->toBeNull();
});
