<?php

namespace App\Observers;

use App\Actions\Follows\FollowShow;
use App\Actions\Follows\SyncFollowState;
use App\Models\Episode;
use App\Models\Play;
use App\Services\Stats\StatsCacheVersion;
use App\Services\Watch\WatchCacheVersion;

class PlayObserver
{
    public function __construct(
        private FollowShow $followShow,
        private SyncFollowState $syncFollowState,
        private StatsCacheVersion $statsCacheVersion,
        private WatchCacheVersion $watchCacheVersion,
    ) {}

    public function created(Play $play): void
    {
        $this->statsCacheVersion->bump();
        $this->watchCacheVersion->bump();

        if (! $play->playable instanceof Episode) {
            return;
        }

        $title = $play->playable->title;

        if (! $title->isShow()) {
            return;
        }

        $follow = $this->followShow->handle($title);

        if ($play->watched_at !== null && ($follow->last_played_at === null || $play->watched_at->greaterThan($follow->last_played_at))) {
            $follow->update(['last_played_at' => $play->watched_at]);
        }

        $this->syncFollowState->handle($follow);
    }

    public function updated(Play $play): void
    {
        $this->statsCacheVersion->bump();
        $this->watchCacheVersion->bump();
    }

    public function deleted(Play $play): void
    {
        $this->statsCacheVersion->bump();
        $this->watchCacheVersion->bump();
    }
}
