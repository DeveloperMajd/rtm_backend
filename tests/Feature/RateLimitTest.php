<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Conversation}
 */
function rateLimitedGroup(): array
{
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $group = Conversation::factory()->create([
        'created_by_user_id' => $admin->id,
        'type' => 'group',
        'title' => 'Limits',
    ]);

    foreach ([$admin, $member] as $user) {
        ConversationParticipant::create([
            'conversation_id' => $group->id,
            'user_id' => $user->id,
            'role' => $user->is($admin) ? 'admin' : 'participant',
            'joined_at' => now(),
        ]);
    }

    return [$admin, $group];
}

// Regression: every throttled route used to share one counter per user, so
// ordinary chatting (read receipts, typing) used up the 10-a-minute
// allowance of routes like deleting a group.
test('a burst on one route does not use up another route\'s limit', function () {
    [$admin, $group] = rateLimitedGroup();

    for ($i = 0; $i < 12; $i++) {
        $this->actingAs($admin)->postJson("/api/conversations/{$group->id}/read")->assertNoContent();
    }

    $this->actingAs($admin)->deleteJson("/api/conversations/{$group->id}")->assertNoContent();
});

test('each route still enforces its own limit', function () {
    [$admin, $group] = rateLimitedGroup();

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($admin)->postJson("/api/conversations/{$group->id}/read")->assertNoContent();
    }

    $this->actingAs($admin)->postJson("/api/conversations/{$group->id}/read")->assertTooManyRequests();
});
