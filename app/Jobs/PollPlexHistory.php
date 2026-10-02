<?php

namespace App\Jobs;

use App\Actions\Plex\ImportRecentHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class PollPlexHistory implements ShouldQueue
{
    use Queueable;

    /**
     * Overlaps the 15-minute schedule so a slow tick can't leave a gap in coverage.
     */
    private const LOOKBACK_MINUTES = 20;

    public function handle(ImportRecentHistory $importRecentHistory): void
    {
        $importRecentHistory->handle(Carbon::now()->subMinutes(self::LOOKBACK_MINUTES));
    }
}
