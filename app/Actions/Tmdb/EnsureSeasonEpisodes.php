<?php

namespace App\Actions\Tmdb;

use App\Actions\Tmdb\Concerns\SyncsSeasonEpisodes;
use App\Jobs\ImportSeasonEpisodes;
use App\Models\Season;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees a season's episodes exist locally right now, synchronously importing
 * them from TMDB if they've never been synced or the sync is stale. Safe to call
 * repeatedly — no-ops once the season is fresh, and locks per-season so concurrent
 * callers don't double-import.
 */
class EnsureSeasonEpisodes
{
    use SyncsSeasonEpisodes;

    public function __construct(private readonly TmdbClient $tmdb) {}

    public function handle(Season $season): Season
    {
        if (! $this->isStale($season)) {
            return $season;
        }

        $lock = Cache::lock("ensure-season-episodes:{$season->id}", 30);

        if (! $lock->get()) {
            // Another caller is already importing this season right now; don't wait on it here.
            return $season->fresh();
        }

        try {
            $season->refresh();

            if ($this->isStale($season)) {
                $seasonsData = $this->tmdb->seasons($season->title->tmdb_id, [$season->season_number]);
                $seasonData = $seasonsData[$season->season_number] ?? null;

                if ($seasonData !== null) {
                    // Retries on deadlock (SQLSTATE 40001/1213), which concurrent
                    // title imports and other writers can trigger.
                    DB::transaction(fn () => $this->syncSeasonEpisodes($season->title, $season, $seasonData), 3);
                }
            }
        } finally {
            $lock->release();
        }

        return $season->fresh();
    }

    /**
     * Non-blocking counterpart to `handle()`: if the season's episode list is stale, queue a job
     * to refresh it instead of importing synchronously. Callers proceed immediately with whatever
     * episodes are already in the DB.
     */
    public function dispatchRefreshIfStale(Season $season): void
    {
        if (! $this->isStale($season)) {
            return;
        }

        ImportSeasonEpisodes::dispatch($season->title_id, $season->season_number);
    }

    private function isStale(Season $season): bool
    {
        if ($season->episodes_synced_at === null) {
            return true;
        }

        return $season->episodes_synced_at->lt(now()->subDays((int) config('showing.episode_refresh_days', 1)));
    }
}
