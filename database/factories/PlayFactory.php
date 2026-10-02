<?php

namespace Database\Factories;

use App\Enums\PlaySource;
use App\Models\Play;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Play>
 */
class PlayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'playable_type' => (new Title)->getMorphClass(),
            'playable_id' => Title::factory(),
            'watched_at' => fake()->dateTimeBetween('-1 year'),
            'source' => PlaySource::Manual,
            'external_id' => null,
        ];
    }
}
