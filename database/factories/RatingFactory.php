<?php

namespace Database\Factories;

use App\Models\Rating;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rating>
 */
class RatingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rateable_type' => (new Title)->getMorphClass(),
            'rateable_id' => Title::factory(),
            'score' => fake()->numberBetween(1, 10),
            'review' => fake()->optional()->sentence(),
        ];
    }
}
