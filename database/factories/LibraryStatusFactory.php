<?php

namespace Database\Factories;

use App\Enums\LibraryState;
use App\Models\LibraryStatus;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LibraryStatus>
 */
class LibraryStatusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title_id' => Title::factory(),
            'state' => LibraryState::Requested,
            'seerr_request_id' => fake()->numberBetween(1, 9999),
            'sonarr_id' => null,
            'radarr_id' => null,
        ];
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes) => ['state' => LibraryState::Available]);
    }
}
