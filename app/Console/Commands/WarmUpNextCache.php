<?php

namespace App\Console\Commands;

use App\Services\CalendarCache;
use App\Services\UpNext\UpNextCache;
use Illuminate\Console\Command;

class WarmUpNextCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'upnext:warm';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recomputes and stores the Up Next and Calendar caches (nightly, shortly after local midnight and the Plex jobs)';

    public function handle(UpNextCache $upNextCache, CalendarCache $calendarCache): int
    {
        $upNextCache->warm();
        $calendarCache->warmDefaults();

        $this->components->info('Up Next and Calendar caches warmed.');

        return self::SUCCESS;
    }
}
