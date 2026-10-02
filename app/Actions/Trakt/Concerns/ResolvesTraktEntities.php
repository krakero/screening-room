<?php

namespace App\Actions\Trakt\Concerns;

use App\Enums\TitleType;
use App\Models\Episode;
use App\Models\Title;

trait ResolvesTraktEntities
{
    /**
     * @param  array<string, mixed>  $entry  A Trakt entry with a `type` key ('movie'|'show') and a matching nested object.
     */
    private function resolveTitle(array $entry): ?Title
    {
        $type = $entry['type'] ?? null;

        if (! in_array($type, ['movie', 'show'], true)) {
            return null;
        }

        $tmdbId = $entry[$type]['ids']['tmdb'] ?? null;

        if ($tmdbId === null) {
            return null;
        }

        return Title::query()
            ->where('type', $type === 'movie' ? TitleType::Movie : TitleType::Show)
            ->where('tmdb_id', $tmdbId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $entry  A Trakt entry with `show` and `episode` keys.
     */
    private function resolveEpisode(array $entry): ?Episode
    {
        $showTmdbId = $entry['show']['ids']['tmdb'] ?? null;
        $seasonNumber = $entry['episode']['season'] ?? null;
        $episodeNumber = $entry['episode']['number'] ?? null;

        if ($showTmdbId === null || $seasonNumber === null || $episodeNumber === null) {
            return null;
        }

        $title = Title::query()
            ->where('type', TitleType::Show)
            ->where('tmdb_id', $showTmdbId)
            ->first();

        if ($title === null) {
            return null;
        }

        return Episode::query()
            ->where('title_id', $title->id)
            ->where('season_number', $seasonNumber)
            ->where('episode_number', $episodeNumber)
            ->first();
    }
}
