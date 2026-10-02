<?php

namespace Database\Factories;

use App\Enums\RatingSource;
use App\Models\ExternalRating;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalRating>
 */
class ExternalRatingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $source = fake()->randomElement(RatingSource::cases());

        return [
            'title_id' => Title::factory(),
            'source' => $source,
            'value' => fake()->randomFloat(1, 0, $source->maxValue()),
            'max' => $source->maxValue(),
            'votes' => fake()->optional()->numberBetween(10, 2_000_000),
            'url' => fake()->optional()->url(),
            'fetched_at' => now(),
        ];
    }
}
