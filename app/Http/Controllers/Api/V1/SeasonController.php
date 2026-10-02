<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tmdb\EnsureSeasonEpisodes;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SeasonResource;
use App\Jobs\RefreshSeasonTrailer;
use App\Models\Season;
use App\Models\Title;
use App\Services\Seasons\SeasonOverview;

class SeasonController extends Controller
{
    public function show(
        Title $title,
        int $seasonNumber,
        EnsureSeasonEpisodes $ensureSeasonEpisodes,
        SeasonOverview $overview,
    ): SeasonResource {
        $season = $title->seasons()->where('season_number', $seasonNumber)->firstOrFail();

        if (! $season->episodesLoaded()) {
            $season = $ensureSeasonEpisodes->handle($season);
        }

        $awaitingTrailer = $this->refreshTrailerIfStale($season);

        $episodes = $season->episodes()->with('plays')->get();

        $since = $title->loadMissing('follow')->follow?->progressSince();

        $previousSeasonNumber = $title->seasons()
            ->where('season_number', '<', $season->season_number)
            ->orderByDesc('season_number')
            ->value('season_number');

        $nextSeasonNumber = $title->seasons()
            ->where('season_number', '>', $season->season_number)
            ->orderBy('season_number')
            ->value('season_number');

        return new SeasonResource(
            $season,
            episodes: $episodes,
            previousSeasonNumber: $previousSeasonNumber,
            nextSeasonNumber: $nextSeasonNumber,
            airedUnwatchedCount: $overview->airedUnwatchedCount($episodes, $since),
            nextEpisode: $overview->nextEpisode($episodes, $since),
            plexPlayUrls: $overview->plexPlayUrls($title, $episodes),
            awaitingTrailer: $awaitingTrailer,
            awaitingPlex: $overview->hasPendingPlex($title, $episodes),
            since: $since,
        );
    }

    /**
     * Queue a trailer fetch when the season has no trailer and hasn't been checked in the last
     * 30 days. Returns whether a refresh was dispatched, for the response's `awaiting_trailer` flag.
     */
    private function refreshTrailerIfStale(Season $season): bool
    {
        if (filled($season->trailer_key)) {
            return false;
        }

        $checkedAt = $season->trailer_checked_at;

        if ($checkedAt === null || $checkedAt->lt(now()->subDays(30))) {
            RefreshSeasonTrailer::dispatch($season);

            return true;
        }

        return false;
    }
}
