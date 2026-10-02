<?php

namespace App\Actions\Plays;

use App\Actions\Tmdb\EnsureSeasonEpisodes;
use App\Enums\WatchedAt;
use App\Models\Episode;
use App\Models\Season;
use App\Support\DisplayTimezone;
use Carbon\CarbonImmutable;

class MarkSeasonWatched
{
    public function __construct(
        private LogPlay $logPlay,
        private EnsureSeasonEpisodes $ensureSeasonEpisodes,
    ) {}

    /**
     * Log a manual play for every already-aired episode in the season that hasn't been watched yet.
     *
     * @return int the number of episodes newly marked watched
     */
    public function handle(Season $season, WatchedAt $when = WatchedAt::Now, ?CarbonImmutable $customDatetime = null): int
    {
        $this->ensureSeasonEpisodes->dispatchRefreshIfStale($season);

        $markedCount = 0;

        $season->episodes()
            ->whereNotNull('air_date')
            ->where('air_date', '<=', DisplayTimezone::today())
            ->get()
            ->each(function (Episode $episode) use ($when, $customDatetime, &$markedCount): void {
                if (! $episode->plays()->exists()) {
                    $this->logPlay->handle($episode, $when, $customDatetime);
                    $markedCount++;
                }
            });

        return $markedCount;
    }
}
