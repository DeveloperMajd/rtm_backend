<?php

use App\Events\MessageSent;
use App\Jobs\DeleteStoredFiles;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

test('a participant downloads a local-disk attachment under its original name and a non-participant is forbidden', function () {
    [$alice, $bob, $conversation] = attachmentConversation();
    $outsider = User::factory()->create();

    $attachmentId = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('shared.png'),
    ])->json('data.id');

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    // Served directly, as a download: the local disk's signed URL can't
    // carry a download disposition, so redirecting there would open the
    // file inline in place of the app.
    $this->actingAs($bob)->get("/api/attachments/{$attachmentId}")
        ->assertOk()
        ->assertDownload('shared.png');

    $this->actingAs($outsider)->get("/api/attachments/{$attachmentId}")
        ->assertForbidden();
});

test('on object storage a participant is redirected to a fresh download url', function () {
    config(['filesystems.default' => 'r2']);
    Storage::fake('r2');
    Storage::disk('r2')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration, array $options) => "https://r2.test/{$path}?disposition=".urlencode($options['ResponseContentDisposition'] ?? ''),
    );
    [$alice, $bob, $conversation] = attachmentConversation();

    $attachmentId = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('shared.png'),
    ])->json('data.id');

    $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    $response = $this->actingAs($bob)->get("/api/attachments/{$attachmentId}");

    $response->assertRedirect();
    expect(urldecode($response->headers->get('Location')))
        ->toStartWith('https://r2.test/attachments/')
        ->toContain('attachment; filename="shared.png"');
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

/**
 * Uploads a file as $sender and sends it in $conversation.
 *
 * @return array{attachment: Attachment, message: Message}
 */
function sendFile(User $sender, Conversation $conversation, string $name = 'photo.png'): array
{
    $attachmentId = test()->actingAs($sender)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image($name),
    ])->json('data.id');

    $messageId = test()->actingAs($sender)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'attachment_ids' => [$attachmentId],
    ])->assertCreated()->json('data.id');

    return ['attachment' => Attachment::findOrFail($attachmentId), 'message' => Message::findOrFail($messageId)];
}

test('an upload not sent yet can be downloaded by its uploader alone', function () {
    [$alice, $bob] = attachmentConversation();

    $attachmentId = $this->actingAs($alice)->postJson('/api/attachments', [
        'file' => UploadedFile::fake()->image('draft.png'),
    ])->json('data.id');

    $this->actingAs($alice)->get("/api/attachments/{$attachmentId}")->assertOk();
    $this->actingAs($bob)->get("/api/attachments/{$attachmentId}")->assertForbidden();
});

test('a member who left can download what was sent before they did, and nothing after', function () {
    [$alice, $bob, $conversation] = attachmentConversation();
    $before = sendFile($alice, $conversation, 'before.png');

    app(ConversationParticipantService::class)->leave($conversation, $bob);
    $after = sendFile($alice, $conversation, 'after.png');

    $this->actingAs($bob)->get("/api/attachments/{$before['attachment']->id}")
        ->assertOk()
        ->assertDownload('before.png');
    $this->actingAs($bob)->get("/api/attachments/{$after['attachment']->id}")
        ->assertNotFound();
});

test('a deleted group’s files can no longer be downloaded', function () {
    [$alice, $bob, $conversation] = attachmentConversation();
    $sent = sendFile($alice, $conversation);

    $conversation->forceFill(['deleted_at' => now()])->save();

    foreach ([$alice, $bob] as $member) {
        $this->actingAs($member)->get("/api/attachments/{$sent['attachment']->id}")->assertNotFound();
    }
});

test('deleting a message deletes its files, and its links stop working', function () {
    [$alice, $bob, $conversation] = attachmentConversation();
    $sent = sendFile($alice, $conversation);
    $kept = sendFile($alice, $conversation, 'kept.png');
    Storage::disk('local')->assertExists($sent['attachment']->path);

    $this->actingAs($alice)->deleteJson("/api/messages/{$sent['message']->id}")->assertNoContent();

    // The queue runs at once in the tests.
    Storage::disk('local')->assertMissing($sent['attachment']->path);
    expect(Attachment::find($sent['attachment']->id))->toBeNull();
    // Still says it had one: the app goes by that to take it out of the
    // shared media.
    expect($sent['message']->fresh()->attachments_count)->toBe(1);

    foreach ([$alice, $bob] as $member) {
        $this->actingAs($member)->get("/api/attachments/{$sent['attachment']->id}")->assertNotFound();
    }

    // Another message's files are left alone.
    Storage::disk('local')->assertExists($kept['attachment']->path);
    $this->actingAs($bob)->get("/api/attachments/{$kept['attachment']->id}")->assertOk();
});

test('deleting a message without files queues nothing', function () {
    [$alice, , $conversation] = attachmentConversation();
    $messageId = $this->actingAs($alice)->postJson('/api/messages', [
        'conversation_id' => $conversation->id,
        'body' => 'just words',
    ])->json('data.id');
    Queue::fake();

    $this->actingAs($alice)->deleteJson("/api/messages/{$messageId}")->assertNoContent();

    Queue::assertNotPushed(DeleteStoredFiles::class);
});

// Messages deleted before their files went with them.
test('a file still held by an already-deleted message cannot be downloaded, and the migration lets it go', function () {
    [$alice, $bob, $conversation] = attachmentConversation();
    $old = sendFile($alice, $conversation, 'old.png');
    $live = sendFile($alice, $conversation, 'live.png');
    $old['message']->forceFill(['body' => '', 'deleted_at' => now()])->save();

    $this->actingAs($bob)->get("/api/attachments/{$old['attachment']->id}")->assertNotFound();

    (require database_path('migrations/2026_10_26_181704_discard_attachments_of_deleted_messages.php'))->up();

    Storage::disk('local')->assertMissing($old['attachment']->path);
    expect(Attachment::find($old['attachment']->id))->toBeNull();
    Storage::disk('local')->assertExists($live['attachment']->path);
    expect(Attachment::find($live['attachment']->id))->not->toBeNull();
});
