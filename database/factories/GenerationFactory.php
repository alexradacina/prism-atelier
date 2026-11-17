<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(), 'user_id' => User::factory(),
            'prompt' => fake()->sentence(), 'size' => '1024x1024', 'quality' => 'medium',
            'reference_images' => [], 'quoted_pricing' => [], 'status' => 'queued',
            'estimated_nanos' => 72_250_000, 'reserved_nanos' => 90_000_000,
        ];
    }
}
