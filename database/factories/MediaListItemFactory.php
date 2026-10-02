<?php

namespace Database\Factories;

use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Models\Title;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaListItem>
 */
class MediaListItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_list_id' => MediaList::factory(),
            'title_id' => Title::factory(),
            'position' => fake()->numberBetween(0, 20),
        ];
    }
}
