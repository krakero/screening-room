<?php

namespace App\Services\Titles;

use App\Actions\Plex\ResolvePlexAvailability;
use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use App\Services\ShowProgress;
use App\Services\ShowProgressData;
use App\Support\DisplayTimezone;
use App\Support\IntegrationSettings;
use Illuminate\Support\Collection;

/**
 * Shared title-detail computations used by both the web title page and the API's
 * title-detail endpoint, so the two never drift apart.
 */
class TitleOverview
{
    public function __construct(
        private readonly ShowProgress $showProgress,
        private readonly ResolvePlexAvailability $plex,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * The season to offer a "Season N trailer" for: the season of the next unwatched episode,
     * or (once caught up / for a show with nothing unwatched) the most recently aired season.
     * Null for movies or a show with no aired seasons yet. Pass an already-computed $progress
     * to skip re-loading every aired episode and play.
     */
    public function currentSeason(Title $title, ?ShowProgressData $progress = null): ?Season
    {
        if (! $title->isShow()) {
            return null;
        }

        $nextEpisode = ($progress ?? $this->showProgress->for($title))->nextEpisode;

        if ($nextEpisode !== null) {
            // `season_id` avoids lazy-loading the `season` relation (disabled outside local dev).
            return Season::find($nextEpisode->season_id);
        }

        // `seasons()` already applies its own `orderBy('season_number')` ascending; `reorder()`
        // clears it so this query's own descending order actually takes effect.
        return $title->seasons()
            ->where('season_number', '!=', 0)
            ->whereNotNull('air_date')
            ->where('air_date', '<=', DisplayTimezone::today())
            ->reorder('season_number', 'desc')
            ->first();
    }

    /**
     * The current season, only when it actually has a trailer to offer.
     */
    public function seasonTrailer(Title $title, ?ShowProgressData $progress = null): ?Season
    {
        $season = $this->currentSeason($title, $progress);

        return $season?->hasTrailer() ? $season : null;
    }

    /**
     * The show's "Up next" episode: the next aired-and-unwatched episode ($nextAiredEpisode,
     * from ShowProgress — pass it in rather than recomputing so callers that already hold it
     * don't pay for the query twice). Once caught up on everything aired, falls back to the
     * lowest-numbered unwatched episode overall (a single cheap query), so an unaired next
     * episode still has something to show ("Airs <date>"), and a never-watched show surfaces
     * S1E1. Null once nothing is left at all (ended and fully watched, or a movie).
     */
    public function nextUpEpisode(Title $title, ?Episode $nextAiredEpisode): ?Episode
    {
        if (! $title->isShow()) {
            return null;
        }

        return $nextAiredEpisode ?? Episode::query()
            ->where('title_id', $title->id)
            ->where('season_number', '!=', 0)
            ->whereDoesntHave('plays')
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->first();
    }

    /**
     * Per-season watched percentage / completeness for the season poster row, keyed by season id.
     * Falls back to the season's TMDB episode_count when its episodes aren't loaded yet.
     *
     * @param  Collection<int, Season>  $seasons
     * @return array<int, array{percent: float, complete: bool}>
     */
    public function seasonProgress(Collection $seasons): array
    {
        $seasons = $seasons->reject(fn (Season $season) => $season->season_number === 0);

        $counts = Episode::query()
            ->whereIn('season_id', $seasons->pluck('id'))
            ->selectRaw("season_id, count(*) as total, sum(exists (select 1 from plays where plays.playable_type = 'episode' and plays.playable_id = episodes.id)) as watched")
            ->groupBy('season_id')
            ->get()
            ->keyBy('season_id');

        return $seasons->mapWithKeys(function (Season $season) use ($counts): array {
            $row = $counts->get($season->id);
            $total = $season->episodesLoaded() ? (int) ($row?->total ?? 0) : ($season->episode_count ?? 0);
            $watched = (int) ($row?->watched ?? 0);

            return [$season->id => [
                'percent' => $total > 0 ? round($watched / $total * 100, 1) : 0.0,
                'complete' => $total > 0 && $watched === $total,
            ]];
        })->all();
    }

    /**
     * The Plex "play" link for this movie, or for a show's next unwatched episode (falling back
     * to the show itself once caught up). Null when Plex isn't configured or the item can't be found.
     */
    public function plexPlayUrl(Title $title, ?ShowProgressData $progress = null): ?string
    {
        if (! $this->settings->configured('plex.url', 'plex.token')) {
            return null;
        }

        if ($title->isMovie()) {
            return $this->plex->forTitle($title)?->playUrl();
        }

        $nextEpisode = ($progress ?? $this->showProgress->for($title))->nextEpisode;

        if ($nextEpisode !== null) {
            return $this->plex->forEpisode($nextEpisode)?->playUrl();
        }

        return $this->plex->forTitle($title)?->playUrl();
    }

    /**
     * True when the Plex link above is still being resolved in the background (no `plex_items`
     * row yet for the show's next unwatched episode) — only episode lookups can be pending;
     * forTitle() never makes a live call, so a movie or a caught-up show is never pending.
     */
    public function plexPending(Title $title, ?ShowProgressData $progress = null): bool
    {
        if (! $title->isShow()) {
            return false;
        }

        $nextEpisode = ($progress ?? $this->showProgress->for($title))->nextEpisode;

        return $nextEpisode !== null && $this->plex->isPendingForEpisode($nextEpisode);
    }
}
