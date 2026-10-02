<?php

namespace App\Observers;

use App\Models\Episode;
use App\Services\Watch\WatchCacheVersion;

/**
 * A new/changed/removed episode (a new season, an air date moving) can move it into or out
 * of the Up Next / Calendar cache's membership. Bumping per-row here (like PlayObserver and
 * FollowObserver) rather than once per import batch is deliberately simple and still cheap —
 * see App\Services\Watch\WatchCacheVersion — since the actual warm it triggers is debounced
 * to one in-flight job regardless of how many rows a season/library import touches.
 */
class EpisodeObserver
{
    public function __construct(private WatchCacheVersion $watchCacheVersion) {}

    public function created(Episode $episode): void
    {
        $this->watchCacheVersion->bump();
    }

    public function updated(Episode $episode): void
    {
        $this->watchCacheVersion->bump();
    }

    public function deleted(Episode $episode): void
    {
        $this->watchCacheVersion->bump();
    }
}
