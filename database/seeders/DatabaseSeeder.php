<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $alice = User::factory()->create([
            'name' => 'Alice Martin',
            'email' => 'alice@example.com',
        ]);

        $bob = User::factory()->create([
            'name' => 'Bob Smith',
            'email' => 'bob@example.com',
        ]);

        $charlie = User::factory()->create([
            'name' => 'Charlie Doe',
            'email' => 'charlie@example.com',
        ]);

        // Conversation 1: direct between Alice and Bob
        $conv1 = Conversation::factory()->create(['created_by_user_id' => $alice->id]);
        $this->addParticipants($conv1->id, [$alice->id, $bob->id]);
        $this->seedMessages($conv1->id, [$alice->id, $bob->id], 4);

        // Conversation 2: direct between Bob and Charlie
        $conv2 = Conversation::factory()->create(['created_by_user_id' => $bob->id]);
        $this->addParticipants($conv2->id, [$bob->id, $charlie->id]);
        $this->seedMessages($conv2->id, [$bob->id, $charlie->id], 4);

        // Conversation 3: group with all three users
        $conv3 = Conversation::factory()->group('The Gang')->create(['created_by_user_id' => $alice->id]);
        $this->addParticipants($conv3->id, [$alice->id, $bob->id, $charlie->id], adminId: $alice->id);
        $this->seedMessages($conv3->id, [$alice->id, $bob->id, $charlie->id], 6);

        // Everyone has everyone else in their contacts.
        $people = [$alice, $bob, $charlie];
        foreach ($people as $person) {
            foreach ($people as $other) {
                if ($person->is($other)) {
                    continue;
                }

                Contact::firstOrCreate([
                    'user_id' => $person->id,
                    'contact_user_id' => $other->id,
                ]);
            }
        }
    }

    /** @param array<string> $userIds */
    private function addParticipants(string $conversationId, array $userIds, ?string $adminId = null): void
    {
        foreach ($userIds as $userId) {
            ConversationParticipant::create([
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'role' => $userId === $adminId ? 'admin' : 'participant',
            ]);
        }
    }

    /** @param array<string> $senderIds */
    private function seedMessages(string $conversationId, array $senderIds, int $count): void
    {
        $last = null;

        for ($i = 0; $i < $count; $i++) {
            $last = Message::factory()->create([
                'conversation_id' => $conversationId,
                'sender_user_id' => $senderIds[$i % count($senderIds)],
            ]);
        }

        Conversation::where('id', $conversationId)->update([
            'last_message_at' => now(),
            'last_message_id' => $last?->id,
        ]);
    }
}
