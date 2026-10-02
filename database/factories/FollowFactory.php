<?php

namespace Database\Factories;

use App\Enums\FollowState;
use App\Models\Follow;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Follow>
 */
class FollowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title_id' => Title::factory()->show(),
            'state' => FollowState::Watching,
            'state_changed_at' => now(),
            'last_played_at' => null,
            'rewatch_started_at' => null,
            'rewatch_count' => 0,
        ];
    }

    public function paused(): static
    {
        return $this->state(['state' => FollowState::Paused]);
    }

    public function abandoned(): static
    {
        return $this->state(['state' => FollowState::Abandoned]);
    }

    public function completed(): static
    {
        return $this->state(['state' => FollowState::Completed]);
    }

    public function rewatching(): static
    {
        return $this->state([
            'state' => FollowState::Watching,
            'rewatch_started_at' => now(),
        ]);
    }
}
