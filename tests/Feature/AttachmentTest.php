<?php

use App\Events\MessageSent;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['filesystems.default' => 'local']);
    Storage::fake('local');
    // The `local` fake disk has no signed-route serving wired up, so give it
    // a deterministic temporary-URL builder for the AttachmentResource.
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration) => "https://cdn.test/{$path}",
    );
});

/**
 * @return array{0: User, 1: User, 2: Conversation}
 */
function attachmentConversation(): array
{
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $conversation = Conversation::factory()->create(['created_by_user_id' => $alice->id]);

    foreach ([$alice, $bob] as $user) {
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => $user->is($alice) ? 'admin' : 'participant',
            'joined_at' => now(),
        ]);
    }

    return [$alice, $bob, $conversation];
}

test('uploading an attachment requires authentication', function () {
    $this->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('photo.jpg'),
    ])->assertUnauthorized();
});

test('an authenticated user can upload an image and its metadata is recorded', function () {
    [$alice] = attachmentConversation();

    $response = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('holiday.jpg', 320, 240),
    ]);

    $response->assertCreated();
    expect($response->json('data.mime_type'))->toBe('image/jpeg');
    expect($response->json('data.is_image'))->toBeTrue();
    expect($response->json('data.width'))->toBe(320);
    expect($response->json('data.height'))->toBe(240);
    expect($response->json('data.message_id'))->toBeNull();
    expect($response->json('data.url'))->toStartWith('https://cdn.test/');

    $attachment = Attachment::firstOrFail();
    expect($attachment->uploaded_by_user_id)->toBe($alice->id);
    expect($attachment->original_name)->toBe('holiday.jpg');
    Storage::disk('local')->assertExists($attachment->path);
});

test('a pdf can be uploaded and has no image dimensions', function () {
    [$alice] = attachmentConversation();

    $response = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->create('report.pdf', 200, 'application/pdf'),
    ]);

    $response->assertCreated();
    expect($response->json('data.is_image'))->toBeFalse();
    expect($response->json('data.width'))->toBeNull();
    expect($response->json('data.height'))->toBeNull();
});

test('a disallowed file type is rejected', function () {
    [$alice] = attachmentConversation();

    $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'),
    ])->assertUnprocessable();
});

test('a file over the size limit is rejected', function () {
    [$alice] = attachmentConversation();

    $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->create('huge.pdf', 20 * 1024, 'application/pdf'),
    ])->assertUnprocessable();
});

test('sending a message links the uploader\'s attachments and counts them', function () {
    Event::fake([MessageSent::class]);
    [$alice, , $conversation] = attachmentConversation();

    $first = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('a.png'),
    ])->json('data.id');
    $second = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->create('b.pdf', 50, 'application/pdf'),
    ])->json('data.id');

    $response = $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'files attached',
        'attachment_ids' => [$first, $second],
    ]);

    $response->assertCreated();
    expect($response->json('data.attachments_count'))->toBe(2);
    expect($response->json('data.attachments'))->toHaveCount(2);

    $messageId = $response->json('data.id');
    expect(Attachment::whereIn('id', [$first, $second])->pluck('message_id')->unique()->all())
        ->toBe([$messageId]);
    expect(Message::find($messageId)->attachments_count)->toBe(2);

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->relationLoaded('attachments')
        && $event->message->attachments->count() === 2);
});

test('a message with only attachments and no body is allowed', function () {
    [$alice, , $conversation] = attachmentConversation();

    $attachmentId = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('solo.png'),
    ])->json('data.id');

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();
});

test('a message with neither body nor attachments is rejected', function () {
    [$alice, , $conversation] = attachmentConversation();

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
    ])->assertUnprocessable();
});

test('a message cannot link an attachment uploaded by someone else', function () {
    [$alice, $bob, $conversation] = attachmentConversation();

    $bobsAttachment = $this->actingAs($bob)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('bob.png'),
    ])->json('data.id');

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'stealing',
        'attachment_ids' => [$bobsAttachment],
    ])->assertUnprocessable();
});

test('an attachment cannot be linked to two messages', function () {
    [$alice, , $conversation] = attachmentConversation();

    $attachmentId = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('once.png'),
    ])->json('data.id');

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'attachment_ids' => [$attachmentId],
    ])->assertUnprocessable();
});

test('a participant is redirected to a download url and a non-participant is forbidden', function () {
    [$alice, $bob, $conversation] = attachmentConversation();
    $outsider = User::factory()->create();

    $attachmentId = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('shared.png'),
    ])->json('data.id');

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    $this->actingAs($bob)->get("/api/attachments/{$attachmentId}")
        ->assertStatus(302);

    $this->actingAs($outsider)->get("/api/attachments/{$attachmentId}")
        ->assertForbidden();
});

test('a deleted message hides its attachments in the resource', function () {
    [$alice, , $conversation] = attachmentConversation();

    $attachmentId = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('gone.png'),
    ])->json('data.id');

    $messageId = $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'here then gone',
        'attachment_ids' => [$attachmentId],
    ])->json('data.id');

    $this->actingAs($alice)->deleteJson("/api/messages/{$messageId}")->assertNoContent();

    $list = $this->actingAs($alice)->getJson("/api/conversations/{$conversation->id}/messages");
    $deleted = collect($list->json('data'))->firstWhere('id', $messageId);

    expect($deleted['attachments'])->toBe([]);
});
