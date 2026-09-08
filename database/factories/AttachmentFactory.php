<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => null,
            'uploaded_by_user_id' => User::factory(),
            'disk' => 'local',
            'path' => 'attachments/'.Str::uuid7().'.png',
            'original_name' => fake()->word().'.png',
            'mime_type' => 'image/png',
            'size_bytes' => fake()->numberBetween(1_000, 500_000),
            'width' => 800,
            'height' => 600,
            'duration_ms' => null,
        ];
    }

    public function pdf(): static
    {
        return $this->state(fn (): array => [
            'path' => 'attachments/'.Str::uuid7().'.pdf',
            'original_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'width' => null,
            'height' => null,
        ]);
    }
}
