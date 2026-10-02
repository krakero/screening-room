<?php

namespace Database\Factories;

use App\Models\PlexLibraryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlexLibraryItem>
 */
class PlexLibraryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plex_rating_key' => (string) fake()->unique()->numberBetween(100, 9999),
            'machine_identifier' => 'abc123def456',
            'type' => 'movie',
            'tmdb_id' => fake()->unique()->numberBetween(1, 999999),
            'imdb_id' => null,
            'tvdb_id' => null,
            'section_key' => '1',
            'indexed_at' => now(),
        ];
    }

    public function show(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'show',
            'section_key' => '2',
        ]);
    }
}
