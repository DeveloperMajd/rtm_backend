<?php

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('contact endpoints require authentication', function () {
    $this->getJson('/api/contacts')->assertUnauthorized();
    $this->getJson('/api/contacts/search?q=ab')->assertUnauthorized();
    $this->postJson('/api/contacts', [])->assertUnauthorized();
});

test('the contact list returns the current user\'s contacts, ordered by name', function () {
    $user = User::factory()->create();
    $zeb = User::factory()->create(['name' => 'Zeb']);
    $ada = User::factory()->create(['name' => 'Ada']);
    User::factory()->create(['name' => 'Not a contact']);

    Contact::create(['user_id' => $user->id, 'contact_user_id' => $zeb->id]);
    Contact::create(['user_id' => $user->id, 'contact_user_id' => $ada->id]);

    $response = $this->actingAs($user)->getJson('/api/contacts')->assertOk();

    expect($response->json('data.*.name'))->toBe(['Ada', 'Zeb']);
});

test('search matches name or email, excluding self and existing contacts', function () {
    $user = User::factory()->create(['name' => 'Searcher Sam']);
    $already = User::factory()->create(['name' => 'Search Existing']);
    $byName = User::factory()->create(['name' => 'Search Target']);
    $byEmail = User::factory()->create(['name' => 'Different', 'email' => 'search-me@example.com']);
    User::factory()->create(['name' => 'Nope', 'email' => 'nope@example.com']);

    Contact::create(['user_id' => $user->id, 'contact_user_id' => $already->id]);

    $ids = collect(
        $this->actingAs($user)->getJson('/api/contacts/search?q=search')->assertOk()->json('data'),
    )->pluck('id');

    expect($ids)->toContain($byName->id)
        ->toContain($byEmail->id)
        ->not->toContain($user->id)
        ->not->toContain($already->id);
});

test('a search query shorter than 2 characters is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/contacts/search?q=a')
        ->assertJsonValidationErrors('q');
});

test('adding a contact creates the contact row and a direct conversation', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/contacts', ['user_id' => $target->id])
        ->assertCreated();

    expect(Contact::where('user_id', $user->id)->where('contact_user_id', $target->id)->exists())->toBeTrue();

    $conversationId = $response->json('data.conversation.id');
    expect($conversationId)->not->toBeNull();
    expect($response->json('data.conversation.type'))->toBe('direct');
    expect($response->json('data.contact.id'))->toBe($target->id);

    $conversation = Conversation::findOrFail($conversationId);
    expect($conversation->participants()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$user->id, $target->id])->sort()->values()->all());
});

test('adding a contact is idempotent and reuses an existing direct conversation', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $conversation = Conversation::factory()->create(['created_by_user_id' => $user->id]);
    foreach ([$user, $target] as $participant) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $participant->id,
            'role' => 'participant',
            'joined_at' => now(),
        ]);
    }

    $first = $this->actingAs($user)->postJson('/api/contacts', ['user_id' => $target->id])->assertCreated();
    $second = $this->actingAs($user)->postJson('/api/contacts', ['user_id' => $target->id])->assertCreated();

    expect($first->json('data.conversation.id'))->toBe($conversation->id);
    expect($second->json('data.conversation.id'))->toBe($conversation->id);
    expect(Contact::where('user_id', $user->id)->where('contact_user_id', $target->id)->count())->toBe(1);
    expect(Conversation::where('type', 'direct')->count())->toBe(1);
});

test('a user cannot add themselves as a contact', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/contacts', ['user_id' => $user->id])
        ->assertJsonValidationErrors('user_id');
});

test('a user can remove a contact without affecting the conversation history', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $conversationId = $this->actingAs($user)
        ->postJson('/api/contacts', ['user_id' => $target->id])
        ->json('data.conversation.id');

    $this->actingAs($user)->deleteJson("/api/contacts/{$target->id}")->assertOk();

    expect(Contact::where('user_id', $user->id)->where('contact_user_id', $target->id)->exists())->toBeFalse();
    expect(Conversation::find($conversationId))->not->toBeNull();
});
