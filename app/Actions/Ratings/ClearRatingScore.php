<?php

namespace App\Actions\Ratings;

use App\Models\Rating;
use App\Models\Title;

class ClearRatingScore
{
    /**
     * Clears the score only. Deletes the rating row entirely if no review remains.
     */
    public function handle(Title $title): ?Rating
    {
        $rating = $title->rating()->first();

        if ($rating === null) {
            return null;
        }

        if ($rating->review !== null) {
            $rating->update(['score' => null]);

            return $rating;
        }

        $rating->delete();

        return null;
    }
}
