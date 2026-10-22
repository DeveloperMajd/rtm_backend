<?php

use App\Enums\MessageReactionType;
use App\Events\MessageReactionUpdated;
use App\Events\MessageSent;
use App\Http\Resources\MessageReactionResource;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Message as HttpMessage;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Every broadcast reaches Reverb as an HTTP request, and Reverb refuses one
// over max_request_size: the queued broadcast fails and the message never
// arrives live, though its sender was told it was sent. These send the
// heaviest broadcasts the app makes through the real Reverb driver (the
// Pusher SDK, which encodes the payload twice), into a recorder instead of
// the network, and measure what Reverb would have had to take.

/**
 * The size of each HTTP request the event's broadcast makes, as Reverb
 * receives it: request line, headers and body.
 *
 * @return list<int>
 */
function broadcastRequestSizes(object $event): array
{
    $requests = [];
    $recorder = HandlerStack::create(new MockHandler(array_fill(0, 10, new Response(200, [], '{}'))));
    $recorder->push(Middleware::history($requests));

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '681379',
        'broadcasting.connections.reverb.options' => ['host' => 'ws.rtm.developermajd.com', 'port' => 443, 'scheme' => 'https', 'useTLS' => true],
        'broadcasting.connections.reverb.client_options' => ['handler' => $recorder],
    ]);
    $broadcasting = app(BroadcastManager::class);
    $broadcasting->purge('reverb');

    (new BroadcastEvent($event))->handle($broadcasting);

    return array_map(fn (array $entry): int => strlen(HttpMessage::toString($entry['request'])), $requests);
}

/**
 * A message in a group of `$members`, as heavy as asked: an Arabic body
 * (the costliest to encode) replying to another as long, photos stored on
 * R2 (each carrying a signed URL), and every member reacting with every
 * reaction there is.
 */
function heavyMessage(int $members, int $arabicCharacters = 0, int $photos = 0, bool $everyReaction = false): Message
{
    // Signed URLs shaped like R2's, signed locally: nothing is sent anywhere.
    config(['filesystems.disks.r2' => [
        'driver' => 's3',
        'key' => str_repeat('k', 32),
        'secret' => str_repeat('s', 64),
        'region' => 'auto',
        'bucket' => 'rtm-attachments',
        'endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com',
    ]]);

    $users = User::factory()->count($members)->create();
    $group = Conversation::factory()->group('Everyone')->create(['created_by_user_id' => $users[0]->id]);
    foreach ($users as $user) {
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $user->id, 'role' => 'participant', 'joined_at' => now()]);
    }

    $body = $arabicCharacters > 0 ? str_repeat('م', $arabicCharacters) : 'Hello';
    $original = Message::factory()->create(['conversation_id' => $group->id, 'sender_user_id' => $users[1]->id, 'body' => $body]);
    $message = Message::factory()->create(['conversation_id' => $group->id, 'sender_user_id' => $users[0]->id, 'body' => $body, 'reply_to_message_id' => $original->id]);

    for ($i = 0; $i < $photos; $i++) {
        Attachment::factory()->create(['message_id' => $message->id, 'disk' => 'r2', 'path' => 'attachments/'.Str::uuid7().'.jpg', 'uploaded_by_user_id' => $users[0]->id]);
    }

    if ($everyReaction) {
        foreach ($users as $user) {
            foreach (MessageReactionType::cases() as $reaction) {
                MessageReaction::create(['message_id' => $message->id, 'user_id' => $user->id, 'reaction' => $reaction]);
            }
        }
    }

    // As the message and reaction controllers load it before broadcasting.
    return $message->load(['sender', 'reactions.user:id,name', 'replyTo.sender:id,name,avatar_url', 'attachments']);
}

// Messages have no length limit yet; 4,000 characters stands for a long one.
dataset('the heaviest broadcasts', [
    'a long Arabic message replying to another' => fn () => new MessageSent(heavyMessage(2, arabicCharacters: 4_000)),
    'ten photos' => fn () => new MessageSent(heavyMessage(2, photos: 10)),
    'a 250-member group' => fn () => new MessageSent(heavyMessage(250)),
    'every member of a 100-member group reacting with every reaction' => fn () => new MessageReactionUpdated(heavyMessage(100, everyReaction: true)),
    'all of that at once' => fn () => new MessageReactionUpdated(heavyMessage(100, arabicCharacters: 4_000, photos: 10, everyReaction: true)),
]);

test('fits what Reverb accepts in one request', function (object $event) {
    $sizes = broadcastRequestSizes($event);

    expect($sizes)->not->toBeEmpty()
        ->each->toBeLessThan(config('reverb.servers.reverb.max_request_size'));
})->with('the heaviest broadcasts');

test('a reaction says who reacted, by name, without their avatar', function () {
    $reaction = MessageReaction::create([
        'message_id' => Message::factory()->create()->id,
        'user_id' => User::factory()->create(['avatar_url' => 'https://example.com/avatar.webp'])->id,
        'reaction' => MessageReactionType::ThumbsUp,
    ]);

    expect((new MessageReactionResource($reaction->load('user')))->resolve()['user'])
        ->toHaveKeys(['id', 'name'])
        ->not->toHaveKey('avatar_url');
});
