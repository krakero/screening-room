<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tmdb_id' => fake()->unique()->numberBetween(1, 999999),
            'name' => fake()->name(),
            'known_for_department' => fake()->randomElement(['Acting', 'Directing', 'Writing']),
            'profile_path' => '/'.fake()->uuid().'.jpg',
        ];
    }
}
