<?php

namespace App\Observers;

use App\Models\Follow;
use App\Services\Stats\StatsCacheVersion;
use App\Services\Watch\WatchCacheVersion;

class FollowObserver
{
    public function __construct(
        private StatsCacheVersion $statsCacheVersion,
        private WatchCacheVersion $watchCacheVersion,
    ) {}

    public function created(Follow $follow): void
    {
        $this->statsCacheVersion->bump();
        $this->watchCacheVersion->bump();
    }

    public function updated(Follow $follow): void
    {
        $this->statsCacheVersion->bump();
        $this->watchCacheVersion->bump();
    }

    public function deleted(Follow $follow): void
    {
        $this->statsCacheVersion->bump();
        $this->watchCacheVersion->bump();
    }
}
