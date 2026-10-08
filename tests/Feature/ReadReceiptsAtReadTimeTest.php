<?php

use App\Events\ConversationRead;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Models\UserSettings;
use App\Services\ReadReceiptVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(RefreshDatabase::class);

// These tests ask about every message after every step, far past the
// per-minute limits, which have tests of their own.
beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * Alice writes, Sam reads and switches his read receipts, and Jordan makes
 * it a group.
 *
 * @return array{0: User, 1: User, 2: Conversation}
 */
function atReadTimeGroup(): array
{
    $alice = User::factory()->create(['name' => 'Alice']);
    $sam = User::factory()->create(['name' => 'Sam']);
    $jordan = User::factory()->create(['name' => 'Jordan']);

    $conversation = Conversation::factory()->group('Team')->create(['created_by_user_id' => $alice->id]);

    foreach ([[$alice, 'admin'], [$sam, 'participant'], [$jordan, 'participant']] as [$user, $role]) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    return [$alice, $sam, $conversation];
}

/**
 * One at a time, so the UUIDv7 ids are in order.
 *
 * @return array<string, Message> keyed by body
 */
function atReadTimeSay(Conversation $conversation, User $sender, string ...$bodies): array
{
    $said = [];

    foreach ($bodies as $body) {
        $said[$body] = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $sender->id,
            'body' => $body,
        ]);
    }

    return $said;
}

/** Reads up to a message, the way the reader's client reports it. */
function atReadTimeRead(TestCase $test, User $reader, Conversation $conversation, Message $upTo): void
{
    $test->actingAs($reader)
        ->postJson("/api/conversations/{$conversation->id}/read", ['message_id' => $upTo->id])
        ->assertNoContent();
}

function atReadTimeSwitch(TestCase $test, User $user, bool $on): void
{
    $test->actingAs($user)->patchJson('/api/settings', ['read_receipts' => $on])->assertOk();
}

/**
 * Which of these messages the viewer is shown as read by the reader, asked
 * two ways that must agree: Message info, and the reader's stretches in
 * GET /reads compared the way the client compares them.
 *
 * @param  array<string, Message>  $messages
 * @return array<int, string> the bodies shown as read
 */
function atReadTimeSeen(TestCase $test, User $viewer, User $reader, Conversation $conversation, array $messages): array
{
    $entry = collect($test->actingAs($viewer)->getJson("/api/conversations/{$conversation->id}/reads")->assertOk()->json('data'))
        ->firstWhere('user_id', $reader->id);

    $seen = [];

    foreach ($messages as $body => $message) {
        $byInfo = collect($test->actingAs($viewer)->getJson("/api/messages/{$message->id}/info")->assertOk()->json('data.read_by') ?? [])
            ->contains('user_id', $reader->id);

        expect(ReadReceiptVisibility::covers($entry, $message->id))->toBe($byInfo, "GET /reads and message info disagree about {$body}");

        if ($byInfo) {
            $seen[] = $body;
        }
    }

    return $seen;
}

function atReadTimeStretches(Conversation $conversation, User $user): ?array
{
    return ConversationParticipant::where('conversation_id', $conversation->id)
        ->where('user_id', $user->id)
        ->first()
        ->receipt_stretches;
}

test('each read counts by the setting it was made under, however often it is switched', function () {
    [$alice, $sam, $conversation] = atReadTimeGroup();

    $messages = atReadTimeSay($conversation, $alice, 'm1', 'm2', 'm3');
    atReadTimeRead($this, $sam, $conversation, $messages['m3']);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1', 'm2', 'm3']);

    // Switching off keeps what was already shared.
    atReadTimeSwitch($this, $sam, false);
    $messages += atReadTimeSay($conversation, $alice, 'm4', 'm5', 'm6');
    atReadTimeRead($this, $sam, $conversation, $messages['m6']);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1', 'm2', 'm3']);

    // Switching back on shows nothing that was read while off...
    atReadTimeSwitch($this, $sam, true);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1', 'm2', 'm3']);

    // ...not even once a later message has been read with it on.
    $messages += atReadTimeSay($conversation, $alice, 'm7', 'm8');
    atReadTimeRead($this, $sam, $conversation, $messages['m8']);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1', 'm2', 'm3', 'm7', 'm8']);

    atReadTimeSwitch($this, $sam, false);
    $messages += atReadTimeSay($conversation, $alice, 'm9', 'm10');
    atReadTimeRead($this, $sam, $conversation, $messages['m10']);
    atReadTimeSwitch($this, $sam, true);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1', 'm2', 'm3', 'm7', 'm8']);
});

test('a message that arrived while they were off still counts when it is read after switching on', function () {
    [$alice, $sam, $conversation] = atReadTimeGroup();

    atReadTimeSwitch($this, $sam, false);
    $messages = atReadTimeSay($conversation, $alice, 'waiting');
    atReadTimeSwitch($this, $sam, true);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe([]);

    atReadTimeRead($this, $sam, $conversation, $messages['waiting']);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['waiting']);
});

// Nobody's screen changes when this ships: until someone switches, their
// whole history follows the setting they have, as it always did.
test('someone who has never switched shares everything, or nothing, as before', function () {
    [$alice, $sam, $conversation] = atReadTimeGroup();
    $jordan = User::where('name', 'Jordan')->first();
    $messages = atReadTimeSay($conversation, $alice, 'm1', 'm2');

    atReadTimeRead($this, $sam, $conversation, $messages['m2']);
    atReadTimeRead($this, $jordan, $conversation, $messages['m2']);
    UserSettings::for($jordan)->fill(['read_receipts' => false])->save();

    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1', 'm2']);
    expect(atReadTimeSeen($this, $alice, $jordan, $conversation, $messages))->toBe([]);
    expect(atReadTimeStretches($conversation, $sam))->toBeNull();
    expect(atReadTimeStretches($conversation, $jordan))->toBeNull();
});

test('in a conversation joined with read receipts off, only what is read after switching on counts', function () {
    [$alice, $sam] = atReadTimeGroup();
    atReadTimeSwitch($this, $sam, false);

    $later = Conversation::factory()->group('Later')->create(['created_by_user_id' => $alice->id]);
    foreach ([$alice, $sam] as $user) {
        ConversationParticipant::create(['conversation_id' => $later->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);
    }

    $messages = atReadTimeSay($later, $alice, 'first');
    atReadTimeRead($this, $sam, $later, $messages['first']);
    atReadTimeSwitch($this, $sam, true);
    expect(atReadTimeSeen($this, $alice, $sam, $later, $messages))->toBe([]);

    $messages += atReadTimeSay($later, $alice, 'second');
    atReadTimeRead($this, $sam, $later, $messages['second']);
    expect(atReadTimeSeen($this, $alice, $sam, $later, $messages))->toBe(['second']);
});

test('a viewer who switches their own off keeps what was shown, and never sees what was read while off', function () {
    [$alice, $sam, $conversation] = atReadTimeGroup();
    $messages = atReadTimeSay($conversation, $alice, 'm1', 'm2');
    atReadTimeRead($this, $sam, $conversation, $messages['m1']);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1']);

    // Turning hers off doesn't take back what she was already shown...
    atReadTimeSwitch($this, $alice, false);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1']);
    expect($this->actingAs($alice)->getJson("/api/messages/{$messages['m1']->id}/info")->json('data.receipts_off'))->toBeTrue();

    // ...but what Sam reads while hers is off isn't shown to her,
    atReadTimeRead($this, $sam, $conversation, $messages['m2']);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1']);

    // not even once she has switched back on.
    atReadTimeSwitch($this, $alice, true);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1']);
    expect($this->actingAs($alice)->getJson("/api/messages/{$messages['m1']->id}/info")->json('data.receipts_off'))->toBeFalse();

    $messages += atReadTimeSay($conversation, $alice, 'm3');
    atReadTimeRead($this, $sam, $conversation, $messages['m3']);
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1', 'm3']);
});

test('a read shows only if both people had read receipts on when it was made', function () {
    [$alice, $sam, $conversation] = atReadTimeGroup();
    $messages = atReadTimeSay($conversation, $alice, 'both on');
    atReadTimeRead($this, $sam, $conversation, $messages['both on']);

    atReadTimeSwitch($this, $sam, false);
    $messages += atReadTimeSay($conversation, $alice, 'reader off');
    atReadTimeRead($this, $sam, $conversation, $messages['reader off']);

    atReadTimeSwitch($this, $alice, false);
    atReadTimeSwitch($this, $sam, true);
    $messages += atReadTimeSay($conversation, $alice, 'viewer off');
    atReadTimeRead($this, $sam, $conversation, $messages['viewer off']);

    atReadTimeSwitch($this, $sam, false);
    $messages += atReadTimeSay($conversation, $alice, 'both off');
    atReadTimeRead($this, $sam, $conversation, $messages['both off']);

    atReadTimeSwitch($this, $sam, true);
    atReadTimeSwitch($this, $alice, true);
    $messages += atReadTimeSay($conversation, $alice, 'both on again');
    atReadTimeRead($this, $sam, $conversation, $messages['both on again']);

    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['both on', 'both on again']);
});

test('a viewer is never given a pointer or a time past what they may see', function () {
    [$alice, $sam, $conversation] = atReadTimeGroup();
    $messages = atReadTimeSay($conversation, $alice, 'm1', 'm2', 'm3');
    $samAsAliceSees = fn () => collect($this->actingAs($alice)->getJson("/api/conversations/{$conversation->id}/reads")->json('data'))
        ->firstWhere('user_id', $sam->id);

    atReadTimeRead($this, $sam, $conversation, $messages['m1']);
    atReadTimeSwitch($this, $alice, false);
    atReadTimeRead($this, $sam, $conversation, $messages['m3']);

    expect($samAsAliceSees()['last_read_message_id'])->toBe($messages['m1']->id);
    expect($samAsAliceSees()['last_read_at'])->toBeNull();

    // Back on, still nothing past m1 until Sam reads something new.
    atReadTimeSwitch($this, $alice, true);
    expect($samAsAliceSees()['last_read_at'])->toBeNull();
    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1']);
});

test('switching records where each other member’s pointer stands, as the switcher will see it', function () {
    [$alice, $sam, $conversation] = atReadTimeGroup();
    $jordan = User::where('name', 'Jordan')->first();
    $messages = atReadTimeSay($conversation, $alice, 'm1', 'm2');
    atReadTimeRead($this, $sam, $conversation, $messages['m2']);

    atReadTimeSwitch($this, $alice, false);
    $viewing = ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $alice->id)->first()->viewer_stretches;
    expect($viewing[$sam->id])->toBe([[null, $messages['m2']->id]]);
    expect($viewing[$jordan->id])->toBe([]);

    atReadTimeSwitch($this, $alice, true);
    $viewing = ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $alice->id)->first()->viewer_stretches;
    expect($viewing[$sam->id])->toBe([[null, $messages['m2']->id], [$messages['m2']->id, null]]);
    expect($viewing[$jordan->id])->toBe([[null, null]]);
});

test('the time of a read is only given when that read was shared', function () {
    [$alice, $sam, $conversation] = atReadTimeGroup();
    $readAt = fn () => collect($this->actingAs($alice)->getJson("/api/conversations/{$conversation->id}/reads")->json('data'))
        ->firstWhere('user_id', $sam->id)['last_read_at'];

    atReadTimeSwitch($this, $sam, false);
    $messages = atReadTimeSay($conversation, $alice, 'm1', 'm2');
    atReadTimeRead($this, $sam, $conversation, $messages['m1']);
    atReadTimeSwitch($this, $sam, true);
    expect($readAt())->toBeNull();

    atReadTimeRead($this, $sam, $conversation, $messages['m2']);
    expect($readAt())->not->toBeNull();
});

test('a switch is recorded only when the setting actually changes', function () {
    [, $sam, $conversation] = atReadTimeGroup();

    atReadTimeSwitch($this, $sam, true);
    $this->actingAs($sam)->patchJson('/api/settings', ['typing_indicators' => false])->assertOk();
    expect(atReadTimeStretches($conversation, $sam))->toBeNull();

    // With nothing read, a stretch closes empty and isn't kept.
    atReadTimeSwitch($this, $sam, false);
    expect(atReadTimeStretches($conversation, $sam))->toBe([]);
    atReadTimeSwitch($this, $sam, true);
    expect(atReadTimeStretches($conversation, $sam))->toBe([[null, null]]);
    atReadTimeSwitch($this, $sam, false);
    expect(atReadTimeStretches($conversation, $sam))->toBe([]);
});

test('the live broadcast carries what was shared, and nothing goes out while off', function () {
    Event::fake([ConversationRead::class]);
    [$alice, $sam, $conversation] = atReadTimeGroup();
    $messages = atReadTimeSay($conversation, $alice, 'm1');

    atReadTimeRead($this, $sam, $conversation, $messages['m1']);
    Event::assertDispatched(ConversationRead::class, fn (ConversationRead $event) => $event->stretches === [[null, null]]);

    atReadTimeSwitch($this, $sam, false);
    $messages += atReadTimeSay($conversation, $alice, 'm2');
    atReadTimeRead($this, $sam, $conversation, $messages['m2']);
    Event::assertDispatchedTimes(ConversationRead::class, 1);

    atReadTimeSwitch($this, $sam, true);
    $messages += atReadTimeSay($conversation, $alice, 'm3');
    atReadTimeRead($this, $sam, $conversation, $messages['m3']);
    Event::assertDispatchedTimes(ConversationRead::class, 2);
    Event::assertDispatched(ConversationRead::class, fn (ConversationRead $event) => $event->lastReadMessageId === $messages['m3']->id
        && $event->stretches === [[null, $messages['m1']->id], [$messages['m2']->id, null]]
        && $event->broadcastWith()['stretches'] === $event->stretches);
});

test('only the newest stretches are kept, and an older one is hidden rather than shown', function () {
    [$alice, $sam, $conversation] = atReadTimeGroup();
    $messages = [];

    for ($i = 1; $i <= ReadReceiptVisibility::MAX_STRETCHES; $i++) {
        $messages += atReadTimeSay($conversation, $alice, "c{$i}");
        atReadTimeRead($this, $sam, $conversation, $messages["c{$i}"]);
        atReadTimeSwitch($this, $sam, false);
        atReadTimeSwitch($this, $sam, true);
    }

    expect(atReadTimeStretches($conversation, $sam))->toHaveCount(ReadReceiptVisibility::MAX_STRETCHES);

    $seen = atReadTimeSeen($this, $alice, $sam, $conversation, $messages);
    expect($seen)->not->toContain('c1');
    expect($seen)->toHaveCount(ReadReceiptVisibility::MAX_STRETCHES - 1);
    expect($seen)->toContain('c'.ReadReceiptVisibility::MAX_STRETCHES);
});

test('an open stretch is never shown for someone who has stopped sharing', function () {
    [$alice, $sam, $conversation] = atReadTimeGroup();
    $messages = atReadTimeSay($conversation, $alice, 'm1', 'm2');
    atReadTimeRead($this, $sam, $conversation, $messages['m2']);

    // The list says open, the setting says off: only what was closed counts.
    ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $sam->id)
        ->update(['receipt_stretches' => json_encode([[null, $messages['m1']->id], [$messages['m1']->id, null]])]);
    UserSettings::for($sam)->fill(['read_receipts' => false])->save();

    expect(atReadTimeSeen($this, $alice, $sam, $conversation, $messages))->toBe(['m1']);
});
