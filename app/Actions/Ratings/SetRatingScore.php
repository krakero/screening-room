<?php

namespace App\Actions\Ratings;

use App\Models\Rating;
use App\Models\Title;

class SetRatingScore
{
    /**
     * @param  int  $score  half-star units: 1 = ½★ … 10 = ★★★★★
     */
    public function handle(Title $title, int $score): Rating
    {
        return $title->rating()->updateOrCreate([], ['score' => $score]);
    }
}
