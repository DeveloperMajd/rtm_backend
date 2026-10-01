<?php

use App\Events\ConversationRead;
use App\Events\TypingIndicator;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Models\UserSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: User, 2: Conversation}
 */
function settingsConversation(): array
{
    $viewer = User::factory()->create();
    $other = User::factory()->create(['last_seen_at' => now()->subHours(3)]);

    $conversation = Conversation::factory()->create(['created_by_user_id' => $viewer->id]);
    foreach ([$viewer, $other] as $user) {
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);
    }

    return [$viewer, $other, $conversation];
}

function setSettings(User $user, array $settings): void
{
    UserSettings::for($user)->fill($settings)->save();
}

function addContact(User $owner, User $contact): void
{
    Contact::create(['user_id' => $owner->id, 'contact_user_id' => $contact->id]);
}

/** Every last_seen_at the viewer is shown for $other, wherever it turns up. */
function lastSeenShown($test, User $viewer, User $other, Conversation $conversation): array
{
    $list = collect($test->actingAs($viewer)->getJson('/api/conversations')->json('data'))->firstWhere('id', $conversation->id);
    $participants = collect($test->actingAs($viewer)->getJson("/api/conversations/{$conversation->id}/participants")->json('data'))
        ->firstWhere('user_id', $other->id);

    return [
        'other_participant' => $list['other_participant']['last_seen_at'],
        'participants' => collect($list['participants'])->firstWhere('user_id', $other->id)['last_seen_at'],
        'participants endpoint' => $participants['last_seen_at'],
    ];
}

test('everyone has the defaults until they change something, and reading them writes nothing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/settings')->assertOk()->assertExactJson(['data' => UserSettings::DEFAULTS]);

    expect(UserSettings::count())->toBe(0);
});

test('changes only what it’s given, and keeps it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/settings', ['message_sounds' => true, 'last_seen_visibility' => 'contacts'])
        ->assertOk()
        ->assertJsonPath('data.message_sounds', true)
        ->assertJsonPath('data.last_seen_visibility', 'contacts')
        ->assertJsonPath('data.read_receipts', true);

    $this->actingAs($user)->patchJson('/api/settings', ['read_receipts' => false])->assertOk();

    expect($this->actingAs($user)->getJson('/api/settings')->json('data'))->toBe([
        'read_receipts' => false,
        'last_seen_visibility' => 'contacts',
        'typing_indicators' => true,
        'message_sounds' => true,
        'desktop_notifications' => false,
    ]);
});

test('refuses nothing to change, and values it doesn’t know', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/settings', [])->assertUnprocessable();
    $this->actingAs($user)->patchJson('/api/settings', ['last_seen_visibility' => 'friends'])->assertUnprocessable();
    $this->actingAs($user)->patchJson('/api/settings', ['typing_indicators' => 'maybe'])->assertUnprocessable();
});

test('last seen: shown everywhere by default', function () {
    [$viewer, $other, $conversation] = settingsConversation();

    expect(lastSeenShown($this, $viewer, $other, $conversation))->each->not->toBeNull();
});

test('last seen: hidden everywhere from everyone when set to nobody — the field stays, empty', function () {
    [$viewer, $other, $conversation] = settingsConversation();
    addContact($other, $viewer);
    setSettings($other, ['last_seen_visibility' => 'nobody']);

    expect(lastSeenShown($this, $viewer, $other, $conversation))->each->toBeNull();

    $contacts = collect($this->actingAs($viewer)->getJson('/api/contacts')->json('data'));
    expect($contacts)->toBeEmpty();
    addContact($viewer, $other);
    $contact = collect($this->actingAs($viewer)->getJson('/api/contacts')->json('data'))->firstWhere('id', $other->id);
    expect($contact)->toHaveKey('last_seen_at');
    expect($contact['last_seen_at'])->toBeNull();
});

// "Contacts" means the people they've added — not the people who've added them.
test('last seen: set to contacts, shown only to people they’ve added', function () {
    [$viewer, $other, $conversation] = settingsConversation();
    setSettings($other, ['last_seen_visibility' => 'contacts']);
    addContact($viewer, $other);

    expect(lastSeenShown($this, $viewer, $other, $conversation))->each->toBeNull();

    addContact($other, $viewer);

    expect(lastSeenShown($this, $viewer, $other, $conversation))->each->not->toBeNull();
});

test('last seen: hidden from the add-contact search too', function () {
    $viewer = User::factory()->create();
    $other = User::factory()->create(['name' => 'Searchable Person', 'last_seen_at' => now()->subHour()]);
    setSettings($other, ['last_seen_visibility' => 'nobody']);

    $match = collect($this->actingAs($viewer)->getJson('/api/contacts/search?q=Searchable')->json('data'))->firstWhere('id', $other->id);

    expect($match['last_seen_at'])->toBeNull();
});

test('a list costs the same few lookups however many people are in it', function () {
    $viewer = User::factory()->create();
    $countFor = function (int $people) use ($viewer): int {
        Contact::where('user_id', $viewer->id)->delete();
        foreach (User::factory()->count($people)->create(['last_seen_at' => now()]) as $person) {
            addContact($viewer, $person);
            setSettings($person, ['last_seen_visibility' => 'contacts']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->getJson('/api/contacts')->assertOk();
        $lookups = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'user_settings') || str_contains($q['query'], '"contacts"'));
        DB::disableQueryLog();

        return $lookups->count();
    };

    expect($countFor(2))->toBe($countFor(8));
});

test('typing indicators off: the ping is accepted and nobody is told', function () {
    Event::fake([TypingIndicator::class]);
    [$viewer, , $conversation] = settingsConversation();

    $this->actingAs($viewer)->postJson("/api/conversations/{$conversation->id}/typing")->assertNoContent();
    Event::assertDispatchedTimes(TypingIndicator::class, 1);

    setSettings($viewer, ['typing_indicators' => false]);
    $this->actingAs($viewer)->postJson("/api/conversations/{$conversation->id}/typing")->assertNoContent();
    Event::assertDispatchedTimes(TypingIndicator::class, 1);
});

test('read receipts off: still reads (unread counts move), but nobody is told', function () {
    Event::fake([ConversationRead::class]);
    [$viewer, $other, $conversation] = settingsConversation();
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $other->id]);
    setSettings($viewer, ['read_receipts' => false]);

    $this->actingAs($viewer)->postJson("/api/conversations/{$conversation->id}/read")->assertNoContent();

    Event::assertNotDispatched(ConversationRead::class);
    $row = collect($this->actingAs($viewer)->getJson('/api/conversations')->json('data'))->firstWhere('id', $conversation->id);
    expect($row['unread_count'])->toBe(0);
});

test('read receipts work both ways: not shown for someone who doesn’t share, nor to them', function () {
    [$viewer, $other, $conversation] = settingsConversation();
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $viewer->id]);
    ConversationParticipant::where('conversation_id', $conversation->id)->update(['last_read_message_id' => $message->id, 'last_read_at' => now()]);

    $pointerFor = fn (User $as, User $of) => collect($this->actingAs($as)->getJson("/api/conversations/{$conversation->id}/reads")->json('data'))
        ->firstWhere('user_id', $of->id)['last_read_message_id'];

    expect($pointerFor($viewer, $other))->toBe($message->id);

    setSettings($other, ['read_receipts' => false]);
    expect($pointerFor($viewer, $other))->toBeNull();
    expect($pointerFor($other, $viewer))->toBeNull();
    // Their own stays theirs to see.
    expect($pointerFor($other, $other))->toBe($message->id);
});
