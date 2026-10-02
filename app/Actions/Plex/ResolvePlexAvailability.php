<?php

namespace App\Actions\Plex;

use App\Jobs\ResolveEpisodePlexAvailability;
use App\Models\Episode;
use App\Models\PlexItem;
use App\Models\PlexLibraryEpisode;
use App\Models\PlexLibraryItem;
use App\Models\Title;
use App\Services\Plex\PlexClient;
use App\Support\IntegrationSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Resolves and caches where a Title (movie or show) or Episode lives on the user's Plex
 * server, so "Watch on Plex" links can be built without an API round-trip on every page load.
 */
class ResolvePlexAvailability
{
    private const CACHE_TTL_HOURS = 24;

    public function __construct(
        private readonly PlexClient $client,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * Reads only the local library index (`plex_library_items`, kept in sync by `plex:index`) —
     * never calls Plex during a page render. `$force` is accepted for symmetry with forEpisode()
     * but is a no-op here: matching against the index is already a cheap indexed query, so there's
     * no staleness to force past, and a stale "not found" `plex_items` row can never block a match
     * that now exists in the index.
     */
    public function forTitle(Title $title, bool $force = false): ?PlexItem
    {
        if (! $this->settings->configured('plex.url', 'plex.token')) {
            return null;
        }

        $match = PlexLibraryItem::query()
            ->where('type', $title->type)
            ->where(function (Builder $query) use ($title): void {
                $query->where('tmdb_id', $title->tmdb_id);

                if ($title->imdb_id !== null) {
                    $query->orWhere('imdb_id', $title->imdb_id);
                }

                if ($title->tvdb_id !== null) {
                    $query->orWhere('tvdb_id', $title->tvdb_id);
                }
            })
            ->first();

        return $this->store($title->plexItem(), $match?->plex_rating_key, $match?->machine_identifier);
    }

    /**
     * Read-only, cache-only lookup — never calls Plex during a page render. Returns the cached
     * `plex_items` row regardless of its age ("I'm fine with stale plex data"). When nothing is
     * cached yet, queues a background job to resolve it (see `refreshEpisode()`) and returns
     * null; the caller can treat "no row yet" as a pending state to poll for.
     */
    public function forEpisode(Episode $episode): ?PlexItem
    {
        if (! $this->settings->configured('plex.url', 'plex.token')) {
            return null;
        }

        $cached = $episode->plexItem()->first();

        if ($cached !== null) {
            return $cached;
        }

        ResolveEpisodePlexAvailability::dispatch($episode);

        return null;
    }

    /**
     * Batched version of forEpisode() for many episodes at once (a season page), avoiding an
     * N+1 query per episode. Missing rows each queue a background resolve job, same as
     * forEpisode().
     *
     * @param  Collection<int, Episode>  $episodes
     * @return Collection<int, PlexItem> keyed by episode id
     */
    public function forEpisodes(Collection $episodes): Collection
    {
        if (! $this->settings->configured('plex.url', 'plex.token') || $episodes->isEmpty()) {
            return collect();
        }

        $cached = PlexItem::query()
            ->where('plexable_type', (new Episode)->getMorphClass())
            ->whereIn('plexable_id', $episodes->pluck('id'))
            ->get()
            ->keyBy('plexable_id');

        foreach ($episodes as $episode) {
            if (! $cached->has($episode->id)) {
                ResolveEpisodePlexAvailability::dispatch($episode);
            }
        }

        return $cached;
    }

    /**
     * The only place a single episode's Plex availability is actually checked against the
     * server — used by the background resolve job, never during a page render.
     * `plex:refresh-availability` uses the batched `refreshEpisodesForShow()` instead, so many
     * stale episodes of one show never cost more than one live lookup between them. `$force`
     * re-checks even a fresh cached row (used by `--force`); a missing/stale row is always
     * re-checked regardless of `$force`.
     *
     * Checks the local `plex_library_episodes` index (built by `plex:index`) before ever calling
     * Plex — only an episode missing from that index (e.g. aired since the last index run) falls
     * back to a live `allLeaves` lookup.
     *
     * @param  ?PlexItem  $show  The episode's show, already resolved via forTitle(). Pass this
     *                           when resolving several episodes of the same show (e.g. a season
     *                           refresh) so the show is only looked up once, not once per episode.
     */
    public function refreshEpisode(Episode $episode, ?PlexItem $show = null, bool $force = false): ?PlexItem
    {
        if (! $this->settings->configured('plex.url', 'plex.token')) {
            return null;
        }

        $cached = $episode->plexItem()->first();

        if ($cached && ! $force && $this->isFresh($cached)) {
            return $cached;
        }

        $show ??= $this->forTitle($episode->loadMissing('title')->title, $force);

        if (! $show?->found()) {
            return $this->store($episode->plexItem(), null, null);
        }

        $indexed = PlexLibraryEpisode::query()
            ->where('show_rating_key', $show->rating_key)
            ->where('machine_identifier', $show->machine_identifier)
            ->where('season_number', $episode->season_number)
            ->where('episode_number', $episode->episode_number)
            ->first();

        if ($indexed !== null) {
            return $this->store($episode->plexItem(), $indexed->plex_rating_key, $show->machine_identifier);
        }

        try {
            $ratingKey = $this->client->episodeRatingKey((string) $show->rating_key, $episode->season_number, $episode->episode_number);
        } catch (Throwable) {
            return $cached;
        }

        return $this->store($episode->plexItem(), $ratingKey, $ratingKey !== null ? $show->machine_identifier : null);
    }

    /**
     * Batched `refreshEpisode()` for every stale episode of ONE show — used by
     * `plex:refresh-availability`, which groups stale episodes by show before calling this.
     * Resolves the show once via `forTitle()` (already network-free — it reads the local
     * `plex_library_items` index), matches every episode against the local
     * `plex_library_episodes` index in a single query, and falls back to at most ONE live
     * `allLeaves` call for the whole show — not one per missing episode — only when the index
     * doesn't have everything (e.g. an episode aired since the last `plex:index` run).
     *
     * @param  Collection<int, Episode>  $episodes
     * @return Collection<int, array{item: ?PlexItem, outcome: 'found'|'not_found'|'error'|'skipped_fresh'}> keyed by episode id
     */
    public function refreshEpisodesForShow(Title $show, Collection $episodes, bool $force = false): Collection
    {
        $results = collect();

        if (! $this->settings->configured('plex.url', 'plex.token') || $episodes->isEmpty()) {
            return $results;
        }

        $toResolve = collect();

        foreach ($episodes as $episode) {
            $cached = $episode->plexItem()->first();

            if ($cached && ! $force && $this->isFresh($cached)) {
                $results[$episode->id] = ['item' => $cached, 'outcome' => 'skipped_fresh'];

                continue;
            }

            $toResolve[$episode->id] = $episode;
        }

        if ($toResolve->isEmpty()) {
            return $results;
        }

        $showItem = $this->forTitle($show, $force);

        if (! $showItem?->found()) {
            foreach ($toResolve as $episode) {
                $results[$episode->id] = ['item' => $this->store($episode->plexItem(), null, null), 'outcome' => 'not_found'];
            }

            return $results;
        }

        $indexed = PlexLibraryEpisode::query()
            ->where('show_rating_key', $showItem->rating_key)
            ->where('machine_identifier', $showItem->machine_identifier)
            ->get()
            ->keyBy(fn (PlexLibraryEpisode $row): string => "{$row->season_number}:{$row->episode_number}");

        $missing = $toResolve->filter(
            fn (Episode $episode): bool => ! $indexed->has("{$episode->season_number}:{$episode->episode_number}")
        );

        $live = null;

        if ($missing->isNotEmpty()) {
            try {
                $live = [];

                foreach ($this->client->showEpisodes((string) $showItem->rating_key, shortTimeout: true) as $row) {
                    $live["{$row['season_number']}:{$row['episode_number']}"] = $row['rating_key'];
                }
            } catch (Throwable) {
                $live = false;
            }
        }

        foreach ($toResolve as $episode) {
            $key = "{$episode->season_number}:{$episode->episode_number}";
            $indexedRow = $indexed->get($key);

            if ($indexedRow !== null) {
                $item = $this->store($episode->plexItem(), $indexedRow->plex_rating_key, $showItem->machine_identifier);
                $results[$episode->id] = ['item' => $item, 'outcome' => 'found'];

                continue;
            }

            if ($live === false) {
                $results[$episode->id] = ['item' => $episode->plexItem()->first(), 'outcome' => 'error'];

                continue;
            }

            $ratingKey = $live[$key] ?? null;
            $item = $this->store($episode->plexItem(), $ratingKey, $ratingKey !== null ? $showItem->machine_identifier : null);
            $results[$episode->id] = ['item' => $item, 'outcome' => $ratingKey !== null ? 'found' : 'not_found'];
        }

        return $results;
    }

    /**
     * How many of these aired episodes have no `plex_items` row yet — a single count query
     * (never a live Plex call), used to drive a season page's pending/polling state without an
     * isPendingForEpisode() query per episode.
     *
     * @param  Collection<int, Episode>  $episodes
     */
    public function pendingEpisodeCount(Collection $episodes): int
    {
        if (! $this->settings->configured('plex.url', 'plex.token') || $episodes->isEmpty()) {
            return 0;
        }

        $cachedCount = PlexItem::query()
            ->where('plexable_type', (new Episode)->getMorphClass())
            ->whereIn('plexable_id', $episodes->pluck('id'))
            ->count();

        return $episodes->count() - $cachedCount;
    }

    /**
     * True when Plex is configured but no `plex_items` row exists yet for this episode — i.e.
     * a resolve job has been (or should be) queued and callers should show a pending/"Checking
     * Plex…" state instead of nothing. False once a row exists, however stale, or when Plex
     * isn't configured at all (nothing was or will be queued).
     */
    public function isPendingForEpisode(Episode $episode): bool
    {
        if (! $this->settings->configured('plex.url', 'plex.token')) {
            return false;
        }

        return $episode->plexItem()->doesntExist();
    }

    /**
     * Cache a known rating key/machine identifier for a Title or Episode without any HTTP
     * round-trip — used when a webhook payload already carries this information (e.g. the
     * event's `Server.uuid` + `Metadata.ratingKey`), which is strictly better than a live lookup.
     */
    public function applyKnown(Title|Episode $model, string $ratingKey, string $machineIdentifier): PlexItem
    {
        return $this->store($model->plexItem(), $ratingKey, $machineIdentifier);
    }

    /**
     * @param  MorphOne<PlexItem, Title|Episode>  $relation
     */
    private function store(MorphOne $relation, ?string $ratingKey, ?string $machineIdentifier): PlexItem
    {
        return $relation->updateOrCreate([], [
            'rating_key' => $ratingKey,
            'machine_identifier' => $machineIdentifier,
            'checked_at' => Carbon::now(),
        ]);
    }

    private function isFresh(PlexItem $item): bool
    {
        return $item->checked_at->isAfter(Carbon::now()->subHours(self::CACHE_TTL_HOURS));
    }
}
