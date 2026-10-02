<?php

namespace App\Observers;

use App\Models\Title;
use App\Services\Watch\WatchCacheVersion;

/**
 * Only deleted is observed: removing a title cascades away its follows/episodes at the DB level
 * (no model events), which changes Up Next / Calendar membership. Creating a title or updating
 * its fields (the hourly tmdb:refresh sync, trailer checks, etc.) doesn't, so neither busts the cache.
 */
class TitleObserver
{
    public function __construct(private WatchCacheVersion $watchCacheVersion) {}

    public function deleted(Title $title): void
    {
        $this->watchCacheVersion->bump();
    }
}
