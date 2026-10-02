<?php

namespace Database\Factories;

use App\Models\PlexLibraryEpisode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlexLibraryEpisode>
 */
class PlexLibraryEpisodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_rating_key' => (string) fake()->unique()->numberBetween(100, 9999),
            'machine_identifier' => 'abc123def456',
            'season_number' => 1,
            'episode_number' => fake()->unique()->numberBetween(1, 9999),
            'plex_rating_key' => (string) fake()->unique()->numberBetween(10000, 99999),
            'indexed_at' => now(),
        ];
    }
}
