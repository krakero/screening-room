<?php

namespace Database\Factories;

use App\Models\PlexItem;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlexItem>
 */
class PlexItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plexable_type' => 'title',
            'plexable_id' => Title::factory(),
            'machine_identifier' => fake()->uuid(),
            'rating_key' => (string) fake()->numberBetween(100, 9999),
            'checked_at' => now(),
        ];
    }

    public function notFound(): static
    {
        return $this->state(fn (array $attributes) => [
            'machine_identifier' => null,
            'rating_key' => null,
        ]);
    }
}
