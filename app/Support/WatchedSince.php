<?php

namespace App\Support;

use App\Models\Play;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Whether a set of plays counts as "watched" for progress purposes — either ever (no `$since`) or
 * only since a rewatch's restart date. A play with no `watched_at` (an "unknown date" log) counts
 * by its `created_at` instead, so plays logged during an active rewatch still count even when the
 * viewer picked "unknown date".
 */
final class WatchedSince
{
    /**
     * @param  Collection<int, Play>  $plays
     */
    public static function watched(Collection $plays, ?CarbonInterface $since): bool
    {
        if ($since === null) {
            return $plays->isNotEmpty();
        }

        return $plays->contains(fn (Play $play): bool => ($play->watched_at ?? $play->created_at)?->greaterThanOrEqualTo($since) ?? false);
    }
}
