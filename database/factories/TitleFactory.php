<?php

namespace Database\Factories;

use App\Enums\TitleType;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Title>
 */
class TitleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TitleType::Movie,
            'tmdb_id' => fake()->unique()->numberBetween(1, 999999),
            'imdb_id' => 'tt'.fake()->unique()->numerify('#######'),
            'tvdb_id' => null,
            'name' => fake()->sentence(3),
            'original_name' => null,
            'original_language' => 'en',
            'tagline' => fake()->sentence(),
            'overview' => fake()->paragraph(),
            'status' => 'Released',
            'in_production' => false,
            'release_date' => fake()->date(),
            'last_air_date' => null,
            'runtime' => fake()->numberBetween(80, 180),
            'genres' => ['Drama'],
            'poster_path' => '/'.fake()->uuid().'.jpg',
            'backdrop_path' => '/'.fake()->uuid().'.jpg',
            'tmdb_synced_at' => now(),
        ];
    }

    /**
     * Indicate that the title is a movie.
     */
    public function movie(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TitleType::Movie,
            'release_date' => fake()->date(),
            'last_air_date' => null,
            'status' => 'Released',
            'in_production' => false,
        ]);
    }

    /**
     * Indicate that the title is a show.
     */
    public function show(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TitleType::Show,
            'release_date' => null,
            'last_air_date' => fake()->date(),
            'status' => 'Returning Series',
            'in_production' => true,
        ]);
    }
}
