<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    /**
     * Sequential season numbers avoid unique (title, season) collisions.
     */
    protected static int $seasonNumber = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title_id' => Title::factory()->show(),
            'tmdb_id' => fake()->unique()->numberBetween(1, 999999),
            'season_number' => ++static::$seasonNumber,
            'name' => fake()->sentence(2),
            'overview' => fake()->paragraph(),
            'air_date' => fake()->date(),
            'poster_path' => '/'.fake()->uuid().'.jpg',
            'episode_count' => fake()->numberBetween(1, 24),
            'episodes_synced_at' => now(),
        ];
    }

    /**
     * A season whose episodes haven't been imported from TMDB yet.
     */
    public function notLoaded(): static
    {
        return $this->state(fn (array $attributes) => [
            'episodes_synced_at' => null,
        ]);
    }
}
