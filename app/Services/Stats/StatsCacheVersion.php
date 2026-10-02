<?php

namespace App\Services\Stats;

use App\Jobs\WarmStatsCache;
use Illuminate\Support\Facades\Cache;

/**
 * A version counter baked into the stats cache keys. Bumping it (cheap, no
 * recompute) invalidates every cached stats entry at once by changing the key
 * they're stored under, instead of hunting down and deleting each key. Each bump also queues a delayed, unique
 * WarmStatsCache job so the next page visit finds the default view already cached.
 */
class StatsCacheVersion
{
    private const KEY = 'stats:version';

    private const WARM_DELAY_SECONDS = 45;

    public function current(): int
    {
        return (int) Cache::get(self::KEY, 1);
    }

    public function bump(): void
    {
        Cache::add(self::KEY, 1, now()->addYear());
        Cache::increment(self::KEY);

        WarmStatsCache::dispatch()->delay(now()->addSeconds(self::WARM_DELAY_SECONDS));
    }
}
