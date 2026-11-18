<?php

namespace Database\Factories;

use App\Models\PixelforgeWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PixelforgeWebhookEvent>
 */
class PixelforgeWebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $generationId = 'gen_'.str_replace('-', '', (string) Str::uuid());
        $eventId = 'evt_'.str_replace('-', '', (string) Str::uuid());

        return [
            'event_id' => $eventId,
            'pixelforge_generation_id' => $generationId,
            'payload' => [
                'id' => $eventId,
                'type' => 'generation.updated',
                'data' => [
                    'id' => $generationId,
                    'status' => 'processing',
                ],
            ],
            'processed_at' => null,
        ];
    }
}
