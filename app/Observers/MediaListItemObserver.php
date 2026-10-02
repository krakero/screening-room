<?php

namespace App\Observers;

use App\Models\MediaListItem;
use App\Services\Watch\WatchCacheVersion;

/**
 * Only created/deleted are observed: adding or removing a list item changes Up Next's
 * "Recently added to Watchlist" membership. Reordering (updated) never affects it.
 */
class MediaListItemObserver
{
    public function __construct(private WatchCacheVersion $watchCacheVersion) {}

    public function created(MediaListItem $mediaListItem): void
    {
        $this->watchCacheVersion->bump();
    }

    public function deleted(MediaListItem $mediaListItem): void
    {
        $this->watchCacheVersion->bump();
    }
}
