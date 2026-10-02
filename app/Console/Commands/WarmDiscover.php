<?php

namespace App\Console\Commands;

use App\Services\Discover\DiscoverFeed;
use Illuminate\Console\Command;

class WarmDiscover extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'discover:warm';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Force-refreshes the cached TMDB payloads behind Discover so the page never waits on TMDB';

    public function handle(DiscoverFeed $discoverFeed): int
    {
        $discoverFeed->warm();

        $this->components->info('Discover feed warmed.');

        return self::SUCCESS;
    }
}
