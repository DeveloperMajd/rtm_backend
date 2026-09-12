<?php

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The testing default (BROADCAST_CONNECTION=null) skips channel-authorization
// entirely — the null driver's auth() is a no-op, so /broadcasting/auth would
// always return 200 regardless of routes/channels.php. Swap in the
// Pusher-protocol driver (what Reverb uses under the hood) with dummy local
// credentials so auth signing runs fully in-process (no network involved) —
// but only right before the /broadcasting/auth call itself, since the same
// driver would try (and fail) to really deliver any ShouldBroadcast event
// raised by setup actions (leaving/kicking) beforehand.
function useLocalPusherAuth(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
    ]);

    // Broadcast::channel() registers against whichever driver is *current*
    // at call time, and routes/channels.php already ran once at boot against
    // the testing default (BROADCAST_CONNECTION=null) — so its channels live
    // on the null driver's registry, not the fresh reverb one just selected
    // above. Re-running the file (Laravel's own bootstrap uses plain
    // `require`, not `require_once`, so this is safe) re-registers the same
    // two channels against the now-current driver.
    require base_path('routes/channels.php');
}

/**
 * @return array{0: User, 1: User, 2: Conversation}
 */
function groupWithHistory(): array
{
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $conversation = Conversation::factory()->group('History')->create(['created_by_user_id' => $admin->id]);

    foreach ([[$admin, 'admin'], [$member, 'participant']] as [$user, $role]) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    Message::factory()->count(3)->create(['conversation_id' => $conversation->id, 'sender_user_id' => $admin->id]);

    return [$admin, $member, $conversation];
}

test('a member who left cannot send messages to the group', function () {
    [, $member, $conversation] = groupWithHistory();

    $this->actingAs($member)->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}")->assertOk();

    $this->actingAs($member)
        ->postJson('/api/messages', ['conversation_id' => $conversation->id, 'body' => 'hello?'])
        ->assertForbidden();
});

test('a kicked member cannot send messages to the group', function () {
    [$admin, $member, $conversation] = groupWithHistory();

    $this->actingAs($admin)->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}/kick")->assertOk();

    $this->actingAs($member)
        ->postJson('/api/messages', ['conversation_id' => $conversation->id, 'body' => 'let me back in'])
        ->assertForbidden();
});

test('a member who left sees history frozen at the moment they left, not messages sent after', function () {
    [$admin, $member, $conversation] = groupWithHistory();

    $this->actingAs($member)->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}")->assertOk();

    // Sent after the member left — must not appear for them.
    $this->actingAs($admin)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'said after they left',
    ])->assertCreated();

    $response = $this->actingAs($member)->getJson("/api/conversations/{$conversation->id}/messages")->assertOk();

    $bodies = collect($response->json('data'))->pluck('body');
    expect($bodies)->not->toContain('said after they left');

    // The "member_left" system line itself is the last thing they should see.
    $last = collect($response->json('data'))->last();
    expect($last['event_type'])->toBe('member_left');
});

test('a member who left still sees the group in their conversation list, marked as left', function () {
    [, $member, $conversation] = groupWithHistory();

    $this->actingAs($member)->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}")->assertOk();

    $response = $this->actingAs($member)->getJson('/api/conversations')->assertOk();
    $data = collect($response->json('data'))->firstWhere('id', $conversation->id);

    expect($data)->not->toBeNull();
    expect($data['viewer_left_at'])->not->toBeNull();
    expect($data['unread_count'])->toBe(0);
});

test('a left group\'s preview does not advance with new activity', function () {
    [$admin, $member, $conversation] = groupWithHistory();

    $this->actingAs($member)->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}")->assertOk();

    $this->actingAs($admin)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'new activity after leaving',
    ])->assertCreated();

    $response = $this->actingAs($member)->getJson('/api/conversations')->assertOk();
    $data = collect($response->json('data'))->firstWhere('id', $conversation->id);

    expect($data['latest_message']['event_type'] ?? null)->toBe('member_left');
});

test('a member who left cannot authorize the conversation broadcast channel', function () {
    [, $member, $conversation] = groupWithHistory();

    $this->actingAs($member)->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}")->assertOk();

    useLocalPusherAuth();

    $this->actingAs($member)->postJson('/broadcasting/auth', [
        'channel_name' => "private-conversation.{$conversation->id}",
        'socket_id' => '1234.5678',
    ])->assertForbidden();
});

test('an active participant can authorize the conversation broadcast channel', function () {
    [$admin, , $conversation] = groupWithHistory();

    useLocalPusherAuth();

    $this->actingAs($admin)->postJson('/broadcasting/auth', [
        'channel_name' => "private-conversation.{$conversation->id}",
        'socket_id' => '1234.5678',
    ])->assertOk();
});

test('a left member does not receive the new-message broadcast fan-out', function () {
    [$admin, $member, $conversation] = groupWithHistory();

    $this->actingAs($member)->deleteJson("/api/conversations/{$conversation->id}/participants/{$member->id}")->assertOk();

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender_user_id' => $admin->id,
    ]);
    $message->load('conversation');

    $event = new MessageSent($message);
    $channelNames = collect($event->broadcastOn())->map->name;

    expect($channelNames)->not->toContain('private-App.Models.User.'.$member->id);
    expect($channelNames)->toContain('private-App.Models.User.'.$admin->id);
});

test('the messages index still requires participation', function () {
    [, , $conversation] = groupWithHistory();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->getJson("/api/conversations/{$conversation->id}/messages")
        ->assertForbidden();
});
