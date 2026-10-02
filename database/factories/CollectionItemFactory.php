<?php

namespace Database\Factories;

use App\Enums\CollectionFormat;
use App\Models\CollectionItem;
use App\Models\Season;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionItem>
 */
class CollectionItemFactory extends Factory
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
            'season_id' => null,
            'format' => fake()->randomElement(CollectionFormat::cases()),
            'edition' => fake()->optional()->randomElement(['Collector\'s Edition', 'Extended Cut', 'Director\'s Cut']),
            'retailer' => fake()->optional()->randomElement(['Amazon', 'Best Buy', 'Target']),
            'barcode' => fake()->optional()->numerify('############'),
            'acquired_at' => fake()->optional()->date(),
            'price' => fake()->optional()->randomFloat(2, 5, 50),
            'currency' => fake()->optional()->currencyCode(),
            'location' => fake()->optional()->randomElement(['Living Room', 'Bedroom', 'Storage']),
            'loaned_to' => null,
            'loaned_at' => null,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function digital(): static
    {
        return $this->state([
            'format' => CollectionFormat::Digital,
            'edition' => null,
            'retailer' => null,
            'barcode' => null,
            'location' => null,
        ]);
    }

    public function loaned(): static
    {
        return $this->state([
            'loaned_to' => fake()->name(),
            'loaned_at' => fake()->date(),
        ]);
    }

    public function forSeason(Season $season): static
    {
        return $this->state([
            'title_id' => $season->title_id,
            'season_id' => $season->id,
        ]);
    }
}
