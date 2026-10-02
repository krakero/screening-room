<?php

namespace App\Actions\Ratings;

use App\Models\Rating;
use App\Models\Title;

class ClearRatingReview
{
    /**
     * Clears the review fields only, keeping the score. Deletes the rating row entirely if there is no score.
     */
    public function handle(Title $title): ?Rating
    {
        $rating = $title->rating()->first();

        if ($rating === null) {
            return null;
        }

        if ($rating->score !== null) {
            $rating->update(['review' => null, 'review_spoilers' => false, 'reviewed_at' => null]);

            return $rating;
        }

        $rating->delete();

        return null;
    }
}
