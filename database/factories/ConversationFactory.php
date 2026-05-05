<?php

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'direct',
            'title' => null,
            'created_by_user_id' => null,
            'last_message_at' => null,
        ];
    }

    public function group(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'group',
            'title' => $title,
        ]);
    }
}
