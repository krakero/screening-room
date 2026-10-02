<?php

namespace App\Jobs;

use App\Services\Stats\StatsService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-fills the Stats page's cache after a bust. Dispatched (delayed) by StatsCacheVersion::bump();
 * unique until it starts processing, so a burst of bumps (imports, Plex polls) collapses into a single warm
 * while a bump that lands mid-warm still queues a fresh one.
 */
class WarmStatsCache implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function handle(StatsService $statsService): void
    {
        $statsService->warm();
    }
}
