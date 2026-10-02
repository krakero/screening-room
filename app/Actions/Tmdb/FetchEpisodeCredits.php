<?php

namespace App\Actions\Tmdb;

use App\Models\Episode;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FetchEpisodeCredits
{
    public function __construct(private readonly TmdbClient $tmdb) {}

    public static function cacheKey(Episode $episode): string
    {
        return "episode-credits:{$episode->tmdb_id}";
    }

    /**
     * Fetch an episode's TMDB cast/crew/rating and cache it for 7 days. Swallows TMDB
     * failures — on failure nothing is cached, so the episode is treated as still pending
     * and the job is re-dispatched on the next request.
     *
     * @return array{cast: array<int, array<string, mixed>>, crew: array<int, array<string, mixed>>, voteAverage: float|null}|null
     */
    public function handle(Episode $episode): ?array
    {
        try {
            $data = $this->tmdb->episode(
                $episode->title->tmdb_id,
                $episode->season_number,
                $episode->episode_number,
            );
        } catch (Throwable) {
            return null;
        }

        $credits = [
            'cast' => $data['credits']['cast'] ?? [],
            'crew' => $data['credits']['crew'] ?? [],
            'voteAverage' => $data['vote_average'] ?? null,
        ];

        Cache::put(self::cacheKey($episode), $credits, now()->addDays(7));

        return $credits;
    }
}
