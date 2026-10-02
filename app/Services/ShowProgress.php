<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\Follow;
use App\Models\Season;
use App\Models\Title;
use App\Support\DisplayTimezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShowProgress
{
    public function airedEpisodeCount(Title $title): int
    {
        return $this->for($title)->airedCount;
    }

    public function watchedEpisodeCount(Title $title): int
    {
        return $this->for($title)->watchedCount;
    }

    public function percent(Title $title): float
    {
        return $this->for($title)->percent;
    }

    /**
     * The lowest unwatched, already-aired episode outside season 0.
     */
    public function nextEpisode(Title $title): ?Episode
    {
        return $this->for($title)->nextEpisode;
    }

    public function isComplete(Title $title): bool
    {
        return $this->for($title)->isComplete;
    }

    public function for(Title $title): ShowProgressData
    {
        return $this->forMany(collect([$title]))->get($title->id);
    }

    /**
     * Batch-computes progress for many shows at once, avoiding N+1 queries.
     *
     * @param  Collection<int, Title>  $titles
     * @return Collection<int, ShowProgressData>
     */
    public function forMany(Collection $titles): Collection
    {
        $titleIds = $titles->pluck('id');

        if ($titleIds->isEmpty()) {
            return collect();
        }

        // Lean rows (no model hydration): only what's needed to count and to find the next episode.
        // The single "next" Episode per title is hydrated afterwards.
        $episodesByTitle = DB::table('episodes')
            ->whereIn('title_id', $titleIds)
            ->where('season_number', '!=', 0)
            ->whereNotNull('air_date')
            ->where('air_date', '<=', DisplayTimezone::today())
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->get(['id', 'title_id'])
            ->groupBy('title_id');

        $playsByEpisodeId = DB::table('plays')
            ->where('playable_type', 'episode')
            ->whereIn('playable_id', $episodesByTitle->flatten(1)->pluck('id'))
            ->get(['playable_id', 'watched_at', 'created_at'])
            ->groupBy('playable_id');

        $seasonsByTitle = Season::query()
            ->whereIn('title_id', $titleIds)
            ->where('season_number', '!=', 0)
            ->get()
            ->groupBy('title_id');

        // While a show is rewatching, progress counts only plays since the restart (see
        // WatchedSince); an unknown-date play still counts if it was logged during the rewatch.
        $rewatchStartByTitleId = Follow::query()
            ->whereIn('title_id', $titleIds)
            ->whereNotNull('rewatch_started_at')
            ->pluck('rewatch_started_at', 'title_id');

        $watchedSince = fn (Collection $plays, ?CarbonInterface $since): bool => $since === null
            ? $plays->isNotEmpty()
            : $plays->contains(fn (object $play): bool => Carbon::parse($play->watched_at ?? $play->created_at)->greaterThanOrEqualTo($since));

        $stats = $titles->mapWithKeys(function (Title $title) use ($episodesByTitle, $playsByEpisodeId, $seasonsByTitle, $rewatchStartByTitleId, $watchedSince): array {
            $titleEpisodes = $episodesByTitle->get($title->id, collect());

            /** @var Collection<int, Season> $titleSeasons */
            $titleSeasons = $seasonsByTitle->get($title->id, collect());
            $unloadedSeasons = $titleSeasons->reject(fn (Season $season): bool => $season->episodesLoaded());

            $since = $rewatchStartByTitleId->get($title->id);

            $isWatched = fn (object $episode): bool => $watchedSince($playsByEpisodeId->get($episode->id, collect()), $since);

            $watchedCount = $titleEpisodes->filter($isWatched)->count();
            $nextEpisodeId = $titleEpisodes->first(fn (object $episode): bool => ! $isWatched($episode))?->id;

            // A season whose episodes haven't been imported yet has no Episode rows to count
            // aired-vs-unaired from; fall back to its TMDB episode_count for the total.
            $airedCount = $titleEpisodes->count() + $unloadedSeasons->sum(fn (Season $season): int => $season->episode_count ?? 0);

            return [$title->id => [$airedCount, $watchedCount, $nextEpisodeId, $unloadedSeasons->isEmpty()]];
        });

        $nextEpisodes = Episode::query()->whereIn('id', $stats->pluck(2)->filter())->get()->keyBy('id');

        return $titles->mapWithKeys(function (Title $title) use ($stats, $nextEpisodes): array {
            [$airedCount, $watchedCount, $nextEpisodeId, $allSeasonsLoaded] = $stats->get($title->id);

            $percent = $airedCount > 0 ? round($watchedCount / $airedCount * 100, 1) : 0.0;
            $isComplete = $airedCount > 0 && $watchedCount === $airedCount && ! $title->in_production && $allSeasonsLoaded;

            return [$title->id => new ShowProgressData($airedCount, $watchedCount, $percent, $nextEpisodeId === null ? null : $nextEpisodes->get($nextEpisodeId), $isComplete)];
        });
    }
}
