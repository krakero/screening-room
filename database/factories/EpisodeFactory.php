<?php

namespace Database\Factories;

use App\Models\Episode;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Episode>
 */
class EpisodeFactory extends Factory
{
    /**
     * Sequential episode numbers avoid unique (title, season, episode) collisions.
     */
    protected static int $episodeNumber = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'title_id' => fn (array $attributes) => $this->findSeason($attributes)?->title_id,
            'tmdb_id' => fake()->unique()->numberBetween(1, 999999),
            'tvdb_id' => null,
            'season_number' => fn (array $attributes) => $this->findSeason($attributes)->season_number ?? 1,
            'episode_number' => ++static::$episodeNumber,
            'name' => fake()->sentence(3),
            'overview' => fake()->paragraph(),
            'air_date' => fake()->dateTimeBetween('-2 years', '-1 week'),
            'runtime' => fake()->numberBetween(20, 65),
            'still_path' => '/'.fake()->uuid().'.jpg',
        ];
    }

    /**
     * Indicate that the episode is a special (season 0).
     */
    public function special(): static
    {
        return $this->state(fn (array $attributes) => [
            'season_number' => 0,
        ]);
    }

    /**
     * Indicate that the episode has already aired.
     */
    public function aired(): static
    {
        return $this->state(fn (array $attributes) => [
            'air_date' => fake()->dateTimeBetween('-2 years', '-1 day'),
        ]);
    }

    /**
     * Indicate that the episode has not aired yet.
     */
    public function unaired(): static
    {
        return $this->state(fn (array $attributes) => [
            'air_date' => fake()->dateTimeBetween('+1 day', '+1 year'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function findSeason(array $attributes): ?Season
    {
        $seasonId = $attributes['season_id'];

        return is_int($seasonId) ? Season::find($seasonId) : null;
    }
}
