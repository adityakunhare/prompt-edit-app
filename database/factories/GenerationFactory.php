<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'pixelforge_id' => 'gen_'.str_replace('-', '', (string) Str::uuid()),
            'status' => 'queued',
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'metadata' => [],
        ];
    }
}
