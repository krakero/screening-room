<?php

namespace App\Observers;

use App\Models\Rating;
use App\Services\Stats\StatsCacheVersion;

class RatingObserver
{
    public function __construct(private StatsCacheVersion $statsCacheVersion) {}

    public function created(Rating $rating): void
    {
        $this->statsCacheVersion->bump();
    }

    public function updated(Rating $rating): void
    {
        $this->statsCacheVersion->bump();
    }

    public function deleted(Rating $rating): void
    {
        $this->statsCacheVersion->bump();
    }
}
