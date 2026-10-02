<?php

namespace App\Services\Watch;

use Illuminate\Support\Facades\Cache;

/**
 * A version counter baked into the Up Next and Calendar cache keys (they share one bust
 * signal — both read the same follow/play/episode data). Bumping it invalidates every cached
 * entry at once by changing the key they're stored under. Mirrors
 * App\Services\Stats\StatsCacheVersion.
 *
 * Deliberately does NOT also dispatch a "warm after bust" job here: this bump fires from
 * common model observers (Play, Follow, Title, Episode) touched by a huge share of the test
 * suite's factories, and a large existing set of tests assert those actions dispatch no
 * unrelated jobs (Queue::assertNothingPushed()). The next read after a bust simply computes
 * once on that miss (see UpNextCache/CalendarCache) — the nightly `upnext:warm` command is
 * what keeps the common case (an unvisited page, first thing in the morning) pre-warmed.
 */
class WatchCacheVersion
{
    private const KEY = 'watch:version';

    public function current(): int
    {
        return (int) Cache::get(self::KEY, 1);
    }

    public function bump(): void
    {
        Cache::add(self::KEY, 1, now()->addYear());
        Cache::increment(self::KEY);
    }
}
