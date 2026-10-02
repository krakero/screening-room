<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Plex\ResolvePlexAvailability;
use App\Actions\Tmdb\FetchEpisodeCredits;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EpisodeDetailResource;
use App\Jobs\ResolveEpisodeCredits;
use App\Models\Episode;
use App\Support\WatchedSince;
use Illuminate\Support\Facades\Cache;

class EpisodeController extends Controller
{
    public function show(Episode $episode, ResolvePlexAvailability $plex): EpisodeDetailResource
    {
        $episode->load(['title.follow', 'season', 'plays']);

        $siblings = Episode::query()
            ->where('title_id', $episode->title_id)
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->get(['id', 'season_number', 'episode_number']);

        $index = $siblings->search(fn (Episode $sibling) => $sibling->id === $episode->id);

        $previousEpisode = $index !== false && $index > 0 ? $siblings->get($index - 1) : null;
        $nextEpisode = $index !== false ? $siblings->get($index + 1) : null;

        $season = $episode->season;

        [$credits, $creditsPending] = $this->creditsOrPending($episode);

        $since = $episode->title->follow?->progressSince();

        $seasonEpisodes = $season->episodes()->with('plays')->get();

        return new EpisodeDetailResource(
            $episode,
            credits: $credits,
            creditsPending: $creditsPending,
            plexUrl: $plex->forEpisode($episode)?->playUrl(),
            plexPending: $plex->isPendingForEpisode($episode),
            previousEpisodeId: $previousEpisode?->id,
            nextEpisodeId: $nextEpisode?->id,
            seasonStats: [
                'total' => $season->episode_count ?? $seasonEpisodes->count(),
                'watched' => $seasonEpisodes->filter(fn (Episode $seasonEpisode) => WatchedSince::watched($seasonEpisode->plays, $since))->count(),
            ],
        );
    }

    /**
     * Crew + rating for the episode, read from the 7-day TMDB cache. Never fetched live during
     * a request: on a cache miss this dispatches `ResolveEpisodeCredits` and returns the empty
     * shape with `$creditsPending = true` so the caller can poll `show()` again shortly after.
     *
     * @return array{0: array{cast: array<int, array<string, mixed>>, crew: array<int, array<string, mixed>>, voteAverage: float|null}, 1: bool}
     */
    private function creditsOrPending(Episode $episode): array
    {
        $empty = ['cast' => [], 'crew' => [], 'voteAverage' => null];

        if (! $episode->hasAired()) {
            return [$empty, false];
        }

        $key = FetchEpisodeCredits::cacheKey($episode);

        if (Cache::has($key)) {
            return [Cache::get($key), false];
        }

        ResolveEpisodeCredits::dispatch($episode);

        return [$empty, true];
    }
}
