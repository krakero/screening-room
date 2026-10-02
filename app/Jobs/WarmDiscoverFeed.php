<?php

namespace App\Jobs;

use App\Services\Discover\DiscoverFeed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WarmDiscoverFeed implements ShouldQueue
{
    use Queueable;

    public function handle(DiscoverFeed $discoverFeed): void
    {
        $discoverFeed->warm();
    }
}
