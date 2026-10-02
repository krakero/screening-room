<?php

namespace Database\Factories;

use App\Enums\CreditType;
use App\Models\Credit;
use App\Models\Person;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credit>
 */
class CreditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'creditable_type' => (new Title)->getMorphClass(),
            'creditable_id' => Title::factory(),
            'type' => CreditType::Cast,
            'character' => fake()->jobTitle(),
            'job' => null,
            'department' => 'Acting',
            'order' => fake()->numberBetween(0, 20),
        ];
    }

    /**
     * Indicate that this is a crew credit.
     */
    public function crew(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CreditType::Crew,
            'character' => null,
            'job' => fake()->randomElement(['Director', 'Writer', 'Screenplay', 'Creator', 'Executive Producer']),
            'department' => 'Directing',
        ]);
    }
}
