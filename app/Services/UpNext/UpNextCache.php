<?php

namespace App\Services\UpNext;

use App\Models\Episode;
use App\Models\Title;
use App\Models\User;
use App\Services\ShowProgressData;
use App\Services\Watch\WatchCacheVersion;
use App\Support\DisplayTimezone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Caches the Up Next page's sections as plain id lists — never hydrated models, which
 * would go stale in cache and are awkward to serialize — keyed by a bumpable version plus the
 * viewer's timezone and local date, so day-boundary sections (Airing This Week) naturally roll
 * over at local midnight without an explicit per-key expiry. Hydration re-queries the DB for
 * the cached ids on every read (a cheap indexed `whereIn`), so relations like `plexItem` that
 * change far more often than section membership (a Plex library resync, a background
 * availability resolve) are always fresh without needing their own bust trigger.
 */
class UpNextCache
{
    /**
     * Comfortably covers a full local day plus slack around the nightly warm; the version +
     * date key make this mostly a safety net rather than the thing driving invalidation.
     */
    private const TTL_HOURS = 26;

    /**
     * Shape of the cached entry. Bump whenever compute()'s keys change so entries written by
     * older code are never read back with the wrong shape (2: recently_watchlisted replaced
     * recently_added and abandoned).
     */
    private const SCHEMA = 2;

    public function __construct(
        private readonly ContinueWatchingQuery $continueWatchingQuery,
        private readonly AiringThisWeekQuery $airingThisWeekQuery,
        private readonly RecentlyWatchlistedQuery $recentlyWatchlistedQuery,
        private readonly WatchCacheVersion $version,
    ) {}

    /**
     * @return Collection<int, array{title: Title, progress: ShowProgressData}>
     */
    public function continueWatching(): Collection
    {
        $ids = $this->entries()['continue_watching'];

        if ($ids === []) {
            return collect();
        }

        $episodes = Episode::query()
            ->whereIn('id', collect($ids)->pluck('episode_id'))
            ->with($this->plexConfigured() ? ['title', 'plexItem'] : ['title'])
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(function (array $entry) use ($episodes): ?array {
                $episode = $episodes->get($entry['episode_id']);

                if ($episode === null) {
                    return null;
                }

                return [
                    'title' => $episode->title,
                    // Only ->nextEpisode is read by any consumer of continueWatching() today
                    // (the dashboard view and the API controller); the other fields exist only
                    // to satisfy ShowProgressData's shape.
                    'progress' => new ShowProgressData(0, 0, 0.0, $episode, false),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, Episode>
     */
    public function airingThisWeek(): Collection
    {
        $ids = $this->entries()['airing_this_week'];

        // No plexItem: unaired episodes aren't on Plex, so Airing This Week shows no Plex marker.
        return $this->hydrateInOrder(Episode::class, $ids, ['title']);
    }

    /**
     * @return Collection<string, Collection<int, Episode>>
     */
    public function airingThisWeekByDay(): Collection
    {
        return $this->airingThisWeek()->groupBy(fn (Episode $episode): string => $episode->air_date->toDateString());
    }

    /**
     * @return Collection<int, Title>
     */
    public function recentlyWatchlisted(): Collection
    {
        $ids = $this->entries()['recently_watchlisted'];

        return $this->hydrateInOrder(Title::class, $ids, $this->recentlyWatchlistedQuery->plexConfigured() ? ['plexItem'] : []);
    }

    public function plexConfigured(): bool
    {
        return $this->recentlyWatchlistedQuery->plexConfigured();
    }

    /**
     * Recomputes and stores all sections right now, regardless of whether a cached entry
     * already exists. Used by the nightly `upnext:warm` command and the post-bust warm job.
     *
     * @return array{continue_watching: array<int, array{title_id: int, episode_id: int}>, airing_this_week: array<int, int>, recently_watchlisted: array<int, int>}
     */
    public function warm(): array
    {
        $data = $this->compute();

        Cache::put($this->key(), $data, now()->addHours(self::TTL_HOURS));

        return $data;
    }

    /**
     * @return array{continue_watching: array<int, array{title_id: int, episode_id: int}>, airing_this_week: array<int, int>, recently_watchlisted: array<int, int>}
     */
    private function entries(): array
    {
        return Cache::remember($this->key(), now()->addHours(self::TTL_HOURS), fn (): array => $this->compute());
    }

    /**
     * @return array{continue_watching: array<int, array{title_id: int, episode_id: int}>, airing_this_week: array<int, int>, recently_watchlisted: array<int, int>}
     */
    private function compute(): array
    {
        return [
            'continue_watching' => $this->continueWatchingQuery->get()
                ->map(fn (array $entry): array => [
                    'title_id' => $entry['title']->id,
                    'episode_id' => $entry['progress']->nextEpisode->id,
                ])
                ->values()
                ->all(),
            'airing_this_week' => $this->airingThisWeekQuery->get()->pluck('id')->all(),
            'recently_watchlisted' => $this->recentlyWatchlistedQuery->get()->pluck('id')->all(),
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $with
     * @return Collection<int, TModel>
     */
    private function hydrateInOrder(string $model, array $ids, array $with): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $models = $model::query()->whereIn('id', $ids)->with($with)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $models->get($id))->filter()->values();
    }

    public function key(): string
    {
        return sprintf(
            'upnext:s%d:v%d:user:%s:tz:%s:date:%s',
            self::SCHEMA,
            $this->version->current(),
            $this->userId(),
            DisplayTimezone::current(),
            DisplayTimezone::today()->toDateString(),
        );
    }

    /**
     * Single-user app, but the key is still scoped by user id per the caching contract, so a
     * future multi-user mode doesn't silently share one account's Up Next with another's.
     */
    private function userId(): int|string
    {
        return Auth::id() ?? User::query()->value('id') ?? 'system';
    }
}
