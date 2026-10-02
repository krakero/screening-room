<?php

namespace App\Actions\Trakt\Concerns;

use App\Enums\TitleType;
use App\Models\Season;
use App\Models\Title;
use Traversable;

trait EnsuresSeasonsLoadedForEpisodes
{
    /**
     * Ensures every show+season referenced by an episode entry has its episodes loaded, one
     * `EnsureSeasonEpisodes` call per distinct show+season (not per entry), so a Trakt import
     * against seasons-only shows doesn't silently skip plays/ratings for unloaded episodes.
     *
     * @param  iterable<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function ensureNeededSeasonsLoaded(iterable $entries): array
    {
        $entries = $entries instanceof Traversable ? iterator_to_array($entries) : (array) $entries;

        collect($entries)
            ->filter(fn (array $entry): bool => isset($entry['episode']['season']))
            ->unique(fn (array $entry): string => ($entry['show']['ids']['tmdb'] ?? '').':'.$entry['episode']['season'])
            ->each(function (array $entry): void {
                $showTmdbId = $entry['show']['ids']['tmdb'] ?? null;
                $seasonNumber = $entry['episode']['season'];

                if ($showTmdbId === null) {
                    return;
                }

                $title = Title::query()->where('type', TitleType::Show)->where('tmdb_id', $showTmdbId)->first();

                if ($title === null) {
                    return;
                }

                $season = Season::query()->where('title_id', $title->id)->where('season_number', $seasonNumber)->first();

                if ($season !== null) {
                    $this->ensureSeasonEpisodes->handle($season);
                }
            });

        return $entries;
    }
}
