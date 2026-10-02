<?php

namespace App\Actions\Tmdb\Concerns;

use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use Illuminate\Support\Carbon;

trait SyncsSeasonEpisodes
{
    /**
     * Upsert a season's episodes from a TMDB `season/{n}` payload and mark the season synced.
     * Episodes no longer present in the payload are removed unless they have plays logged.
     *
     * New/changed air dates can move episodes into or out of the Up Next / Calendar cache's
     * membership; Episode's own #[ObservedBy(EpisodeObserver::class)] bumps the watch cache
     * version for every created/updated/deleted row here, so no explicit bump is needed.
     *
     * @param  array<string, mixed>  $seasonData
     */
    private function syncSeasonEpisodes(Title $title, Season $season, array $seasonData): void
    {
        $episodes = $seasonData['episodes'] ?? [];

        $tmdbIds = collect($episodes)->pluck('id')->all();

        foreach ($episodes as $episodeData) {
            Episode::updateOrCreate(
                ['tmdb_id' => $episodeData['id']],
                [
                    'title_id' => $title->id,
                    'season_id' => $season->id,
                    'season_number' => $episodeData['season_number'] ?? $season->season_number,
                    'episode_number' => $episodeData['episode_number'],
                    'name' => $episodeData['name'] ?? null,
                    'overview' => $episodeData['overview'] ?? null,
                    'air_date' => $this->nullableDate($episodeData['air_date'] ?? null),
                    'runtime' => $episodeData['runtime'] ?? null,
                    'still_path' => $episodeData['still_path'] ?? null,
                ],
            );
        }

        $season->episodes()
            ->whereNotIn('tmdb_id', $tmdbIds)
            ->whereDoesntHave('plays')
            ->get()
            ->each->delete();

        $season->forceFill([
            'episode_count' => count($episodes),
            'episodes_synced_at' => Carbon::now(),
        ])->save();
    }

    private function nullableDate(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
