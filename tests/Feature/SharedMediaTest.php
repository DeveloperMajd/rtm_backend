<?php

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * Alice and Bob in a group called Team, and Carol, who isn't in it.
 *
 * @return array{0: User, 1: User, 2: User, 3: Conversation}
 */
function sharedGroup(): array
{
    [$alice, $bob, $carol] = User::factory()->count(3)->create()->all();
    $group = Conversation::factory()->group('Team')->create(['created_by_user_id' => $alice->id]);
    foreach ([$alice, $bob] as $user) {
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);
    }

    return [$alice, $bob, $carol, $group];
}

/** A message from $sender carrying the given attachments, one at a time so the ids rise. */
function sharedPost(Conversation $conversation, User $sender, string ...$kinds): Message
{
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $sender->id, 'body' => '']);
    foreach ($kinds as $kind) {
        $factory = Attachment::factory()->state(['message_id' => $message->id, 'uploaded_by_user_id' => $sender->id]);
        ($kind === 'pdf' ? $factory->pdf() : $factory)->create();
    }
    $message->forceFill(['attachments_count' => count($kinds)])->save();

    return $message;
}

function sharedUrl(Conversation $conversation, string $kind, string $query = ''): string
{
    return "/api/conversations/{$conversation->id}/attachments?kind={$kind}{$query}";
}

/** @return array<int, string> */
function sharedIds(TestResponse $response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

test('lists a conversation’s photos, newest first, with who sent each and when, and how many there are', function () {
    [$alice, $bob, , $group] = sharedGroup();
    $first = sharedPost($group, $bob, 'image', 'image');
    sharedPost($group, $alice, 'pdf');
    $last = sharedPost($group, $alice, 'image');

    $response = $this->actingAs($alice)->getJson(sharedUrl($group, 'media'))->assertOk();

    $expected = [$last->attachments()->value('id'), ...$first->attachments()->orderByDesc('id')->pluck('id')];
    expect(sharedIds($response))->toBe($expected);
    $response->assertJsonPath('meta', ['total' => 3, 'has_more' => false, 'next_before_id' => null])
        ->assertJsonPath('data.0.is_image', true)
        ->assertJsonPath('data.0.sender.name', $alice->name)
        ->assertJsonPath('data.2.sender.name', $bob->name)
        ->assertJsonPath('data.2.message_id', $first->id);
    expect($response->json('data.0.url'))->toBeString()
        ->and($response->json('data.0.sent_at'))->not->toBeNull();
});

test('lists the other files on their own', function () {
    [$alice, $bob, , $group] = sharedGroup();
    sharedPost($group, $bob, 'image');
    $pdf = sharedPost($group, $bob, 'pdf');

    $response = $this->actingAs($alice)->getJson(sharedUrl($group, 'files'))->assertOk();

    expect(sharedIds($response))->toBe([$pdf->attachments()->value('id')]);
    $response->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.is_image', false)
        ->assertJsonPath('data.0.mime_type', 'application/pdf');
});

test('reads them a page at a time', function () {
    [$alice, $bob, , $group] = sharedGroup();
    foreach (range(1, 3) as $i) {
        sharedPost($group, $bob, 'image');
    }
    $all = Attachment::query()->orderByDesc('id')->pluck('id')->all();

    $page = $this->actingAs($alice)->getJson(sharedUrl($group, 'media', '&limit=2'))->assertOk();
    expect(sharedIds($page))->toBe(array_slice($all, 0, 2));
    $page->assertJsonPath('meta', ['total' => 3, 'has_more' => true, 'next_before_id' => $all[1]]);

    $next = $this->actingAs($alice)->getJson(sharedUrl($group, 'media', '&limit=2&before_id='.$all[1]))->assertOk();
    expect(sharedIds($next))->toBe([$all[2]]);
    $next->assertJsonPath('meta', ['total' => 3, 'has_more' => false, 'next_before_id' => null]);
});

test('leaves out a message deleted since, another conversation’s, and uploads not sent yet', function () {
    [$alice, $bob, , $group] = sharedGroup();
    $kept = sharedPost($group, $bob, 'image');
    $deleted = sharedPost($group, $bob, 'image');
    $deleted->forceFill(['body' => '', 'deleted_at' => now()])->save();
    [, , , $elsewhere] = sharedGroup();
    sharedPost($elsewhere, $bob, 'image');
    Attachment::factory()->create(['uploaded_by_user_id' => $alice->id]);

    $response = $this->actingAs($alice)->getJson(sharedUrl($group, 'media'))->assertOk();

    expect(sharedIds($response))->toBe([$kept->attachments()->value('id')]);
    $response->assertJsonPath('meta.total', 1);
});

test('shows a member who left only what was sent while they were in it', function () {
    [$alice, $bob, , $group] = sharedGroup();
    $before = sharedPost($group, $bob, 'image');
    $leaving = Message::factory()->create(['conversation_id' => $group->id, 'sender_user_id' => null, 'type' => 'system', 'event_type' => 'member_left', 'body' => 'Alice left']);
    ConversationParticipant::where('conversation_id', $group->id)->where('user_id', $alice->id)
        ->update(['left_at' => now(), 'left_at_message_id' => $leaving->id]);
    sharedPost($group, $bob, 'image');

    $response = $this->actingAs($alice)->getJson(sharedUrl($group, 'media'))->assertOk();

    expect(sharedIds($response))->toBe([$before->attachments()->value('id')]);
});

test('is closed to anyone outside the conversation, and gone with a deleted group', function () {
    [$alice, $bob, $carol, $group] = sharedGroup();
    sharedPost($group, $bob, 'image');

    $this->actingAs($carol)->getJson(sharedUrl($group, 'media'))->assertForbidden();

    $group->forceFill(['deleted_at' => now()])->save();
    $this->actingAs($alice)->getJson(sharedUrl($group, 'media'))->assertNotFound();
});

test('needs a kind it knows, and a cursor that’s a uuid', function () {
    [$alice, , , $group] = sharedGroup();

    $this->actingAs($alice)->getJson("/api/conversations/{$group->id}/attachments")->assertUnprocessable()->assertJsonValidationErrors('kind');
    $this->actingAs($alice)->getJson(sharedUrl($group, 'videos'))->assertUnprocessable()->assertJsonValidationErrors('kind');
    $this->actingAs($alice)->getJson(sharedUrl($group, 'media', '&before_id=nope'))->assertUnprocessable()->assertJsonValidationErrors('before_id');
    $this->actingAs($alice)->getJson('/api/conversations/'.Str::uuid7().'/attachments?kind=media')->assertNotFound();
});

test('costs the same few queries however many people sent photos', function () {
    $queriesWith = function (int $senders): int {
        [$alice, , , $group] = sharedGroup();
        foreach (User::factory()->count($senders)->create() as $sender) {
            ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $sender->id, 'role' => 'participant', 'joined_at' => now()]);
            sharedPost($group, $sender, 'image');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($alice)->getJson(sharedUrl($group, 'media'))->assertOk()->assertJsonCount($senders, 'data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesWith(2))->toBe($queriesWith(6));
});

test('you have to be signed in', function () {
    [, , , $group] = sharedGroup();

    $this->getJson(sharedUrl($group, 'media'))->assertUnauthorized();
});
