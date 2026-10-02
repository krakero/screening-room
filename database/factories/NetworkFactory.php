<?php

namespace Database\Factories;

use App\Models\Network;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Network>
 */
class NetworkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tmdb_id' => fake()->unique()->numberBetween(1, 5000),
            'name' => fake()->unique()->company(),
            'logo_path' => '/'.fake()->lexify('??????????').'.png',
            'origin_country' => 'US',
        ];
    }
}
