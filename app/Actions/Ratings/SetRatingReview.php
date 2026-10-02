<?php

namespace App\Actions\Ratings;

use App\Models\Rating;
use App\Models\Title;
use Illuminate\Support\Carbon;

class SetRatingReview
{
    /**
     * Deletes the rating row entirely when the review is cleared and there is no score.
     */
    public function handle(Title $title, ?string $review, bool $spoilers, ?Carbon $reviewedAt): ?Rating
    {
        $rating = $title->rating()->first();

        if ($review === null && $rating?->score === null) {
            $rating?->delete();

            return null;
        }

        return $title->rating()->updateOrCreate([], [
            'review' => $review,
            'review_spoilers' => $review !== null && $spoilers,
            'reviewed_at' => $review !== null ? $reviewedAt : null,
        ]);
    }
}
