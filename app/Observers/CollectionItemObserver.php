<?php

namespace App\Observers;

use App\Models\CollectionItem;
use App\Services\Stats\StatsCacheVersion;

class CollectionItemObserver
{
    public function __construct(
        private StatsCacheVersion $statsCacheVersion,
    ) {}

    /**
     * Handle the CollectionItem "created" event.
     */
    public function created(CollectionItem $collectionItem): void
    {
        $this->statsCacheVersion->bump();
    }

    /**
     * Handle the CollectionItem "updated" event.
     */
    public function updated(CollectionItem $collectionItem): void
    {
        $this->statsCacheVersion->bump();
    }

    /**
     * Handle the CollectionItem "deleted" event.
     */
    public function deleted(CollectionItem $collectionItem): void
    {
        $this->statsCacheVersion->bump();
    }
}
