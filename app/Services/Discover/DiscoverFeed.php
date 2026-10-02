<?php

namespace App\Services\Discover;

use App\Enums\TitleType;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Title;
use App\Services\Tmdb\TmdbClient;
use App\Services\Tmdb\TmdbException;
use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DiscoverFeed
{
    /**
     * Cache::flexible()'s fresh window: a read within this age is served with no TMDB call at
     * all. Matches the warm schedule (everySixHours) so a healthy warm always lands before a
     * read would otherwise go stale.
     */
    private const FRESH_TTL_HOURS = 6;

    /**
     * Cache::flexible()'s stale window: a read past the fresh window but within this age is
     * still served instantly, with a refresh deferred to run after the response. Only a read
     * past this age (or a fully cold key) blocks on TMDB.
     */
    private const STALE_TTL_HOURS = 24;

    private const SEED_WINDOW_DAYS = 90;

    private const MAX_RECOMMENDATION_SHELVES = 3;

    public function __construct(
        private readonly TmdbClient $tmdb,
    ) {}

    /**
     * @return array<int, DiscoverItem>
     */
    public function trending(): array
    {
        $data = $this->cached('discover:trending:all:week', fn (): array => $this->tmdb->trending('all', 'week'));

        $raw = collect($data['results'] ?? [])
            ->filter(fn (mixed $result): bool => is_array($result) && in_array($result['media_type'] ?? null, ['movie', 'tv'], true));

        return $this->hydrate($this->toRawItems($raw));
    }

    /**
     * @return array<int, DiscoverItem>
     */
    public function inTheatersAndComingSoon(): array
    {
        $region = $this->tmdb->region();

        $nowPlaying = $this->cached("discover:now_playing:{$region}", fn (): array => $this->tmdb->nowPlayingMovies($region));

        $upcoming = $this->cached("discover:upcoming:{$region}", fn (): array => $this->tmdb->upcomingMovies($region));

        $raw = $this->toRawItems(collect($nowPlaying['results'] ?? []), TitleType::Movie)
            ->concat($this->toRawItems(collect($upcoming['results'] ?? []), TitleType::Movie))
            ->unique('tmdb_id')
            ->sortBy('release_date')
            ->values();

        return $this->hydrate($raw, markReReleases: true);
    }

    /**
     * @return array<int, DiscoverItem>
     */
    public function newEpisodesThisWeek(): array
    {
        $data = $this->cached('discover:on_the_air', fn (): array => $this->tmdb->onTheAirShows());

        return $this->hydrate($this->toRawItems(collect($data['results'] ?? []), TitleType::Show));
    }

    /**
     * @return array<int, DiscoverShelf>
     */
    public function becauseYouWatched(): array
    {
        $seedTitleIds = $this->seedTitleIds();

        if ($seedTitleIds === []) {
            return [];
        }

        $seedTitles = Title::whereIn('id', $seedTitleIds)->get()->keyBy('id');

        $shelves = [];

        foreach ($seedTitleIds as $seedTitleId) {
            $seedTitle = $seedTitles->get($seedTitleId);

            if ($seedTitle === null) {
                continue;
            }

            $data = $this->cached(
                $this->recommendationsKey($seedTitle),
                fn (): array => $this->tmdb->recommendations($seedTitle->type, $seedTitle->tmdb_id),
            );

            $raw = $this->toRawItems(collect($data['results'] ?? []), $seedTitle->type);

            $items = array_values(array_filter(
                $this->hydrate($raw),
                fn (DiscoverItem $item): bool => ! $item->watched && ! $item->onList && ! $item->followed,
            ));

            if ($items !== []) {
                $shelves[] = new DiscoverShelf(
                    heading: __('Because you watched :title', ['title' => $seedTitle->name]),
                    items: $items,
                );
            }
        }

        return $shelves;
    }

    /**
     * Drop every cached raw TMDB payload the feed reads (and flexible()'s freshness markers),
     * e.g. after a restore replaced the titles the recommendation shelves are seeded from.
     */
    public function forgetCached(): void
    {
        $region = $this->tmdb->region();

        $keys = [
            'discover:trending:all:week',
            "discover:now_playing:{$region}",
            "discover:upcoming:{$region}",
            'discover:on_the_air',
        ];

        foreach (Title::whereIn('id', $this->seedTitleIds())->get() as $seedTitle) {
            $keys[] = $this->recommendationsKey($seedTitle);
        }

        foreach ($keys as $key) {
            Cache::forget($key);
            Cache::forget(Repository::FLEXIBLE_CREATED_KEY_PREFIX.$key);
        }
    }

    /**
     * Force-refresh every raw TMDB payload the feed reads, so page loads never hit TMDB.
     * A failure in one source is reported; the existing cached value (if any) is left in
     * place rather than being cleared, so a bad TMDB response never blanks the feed.
     */
    public function warm(): void
    {
        $region = $this->tmdb->region();

        $sources = [
            'discover:trending:all:week' => fn (): array => $this->tmdb->trending('all', 'week'),
            "discover:now_playing:{$region}" => fn (): array => $this->tmdb->nowPlayingMovies($region),
            "discover:upcoming:{$region}" => fn (): array => $this->tmdb->upcomingMovies($region),
            'discover:on_the_air' => fn (): array => $this->tmdb->onTheAirShows(),
        ];

        foreach (Title::whereIn('id', $this->seedTitleIds())->get() as $seedTitle) {
            $sources[$this->recommendationsKey($seedTitle)] = fn (): array => $this->tmdb->recommendations($seedTitle->type, $seedTitle->tmdb_id);
        }

        foreach ($sources as $key => $fetch) {
            try {
                $value = $fetch();
            } catch (TmdbException $exception) {
                report($exception);

                continue;
            }

            $this->putFresh($key, $value);
        }
    }

    /**
     * Cache::flexible() reads treat a key as fresh only while its own
     * `Repository::FLEXIBLE_CREATED_KEY_PREFIX` timestamp key is within the fresh window, not
     * based on when the value key was last written. A plain Cache::put() here would leave that
     * timestamp at its last background-refresh time (or unset), so flexible() would think the
     * value warm() just wrote is already stale and re-fetch it from TMDB on the very next read.
     * There's no public "mark this flexible key fresh" API, so this writes flexible's own
     * bookkeeping key directly, matching what Repository::flexible() itself stores.
     *
     * @param  array<string, mixed>  $value
     */
    private function putFresh(string $key, array $value): void
    {
        $ttl = now()->addHours(self::STALE_TTL_HOURS);

        Cache::put($key, $value, $ttl);
        Cache::put(Repository::FLEXIBLE_CREATED_KEY_PREFIX.$key, now()->getTimestamp(), $ttl);
    }

    /**
     * Cache::flexible(): a read within the fresh window is served with no TMDB call; a read
     * past it but within the stale window is served instantly and refreshed in the background
     * after the response; only a cold key blocks on TMDB. If the (possibly deferred) refresh's
     * TMDB call fails, the exception is reported and the existing stale value is returned so
     * flexible() keeps serving it rather than the failure surfacing to the caller — a cold key
     * has no existing value to fall back to, so it rethrows.
     *
     * @param  callable(): array<string, mixed>  $fetch
     * @return array<string, mixed>
     */
    private function cached(string $key, callable $fetch): array
    {
        return Cache::flexible(
            $key,
            [now()->addHours(self::FRESH_TTL_HOURS), now()->addHours(self::STALE_TTL_HOURS)],
            function () use ($key, $fetch) {
                try {
                    return $fetch();
                } catch (TmdbException $exception) {
                    report($exception);

                    $stale = Cache::get($key);

                    if ($stale === null) {
                        throw $exception;
                    }

                    return $stale;
                }
            },
        );
    }

    private function recommendationsKey(Title $title): string
    {
        return "discover:recommendations:{$title->type->value}:{$title->tmdb_id}";
    }

    /**
     * Normalize decoded TMDB list results into a flat shape ready for hydration.
     *
     * @param  Collection<int, mixed>  $results
     * @return Collection<int, array{tmdb_id: int, type: TitleType, name: string, year: ?string, poster: ?string, release_date: ?string}>
     */
    private function toRawItems(Collection $results, ?TitleType $forceType = null): Collection
    {
        return $results
            ->filter(fn (mixed $result): bool => is_array($result) && isset($result['id']))
            ->map(function (array $result) use ($forceType): array {
                $type = $forceType ?? ($result['media_type'] === 'movie' ? TitleType::Movie : TitleType::Show);
                $releaseDate = $result['release_date'] ?? $result['first_air_date'] ?? null;

                return [
                    'tmdb_id' => (int) $result['id'],
                    'type' => $type,
                    'name' => $result['title'] ?? $result['name'] ?? '',
                    'year' => $releaseDate ? (substr($releaseDate, 0, 4) ?: null) : null,
                    'poster' => $this->posterUrl($result['poster_path'] ?? null),
                    'release_date' => $releaseDate,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array{tmdb_id: int, type: TitleType, name: string, year: ?string, poster: ?string, release_date: ?string}>  $rawItems
     * @return array<int, DiscoverItem>
     */
    private function hydrate(Collection $rawItems, bool $markReReleases = false): array
    {
        $existing = $this->existingTitles($rawItems);

        return $rawItems->map(function (array $raw) use ($existing, $markReReleases): DiscoverItem {
            $title = $existing->get("{$raw['type']->value}:{$raw['tmdb_id']}");

            return new DiscoverItem(
                tmdbId: $raw['tmdb_id'],
                type: $raw['type'],
                name: $raw['name'],
                year: $raw['year'],
                poster: $raw['poster'],
                title: $title,
                watched: $title ? $this->isWatched($title) : false,
                status: $title?->libraryStatus?->state->value,
                onList: (bool) ($title?->on_list ?? false),
                followed: (bool) ($title?->is_followed ?? false),
                isReRelease: $markReReleases && $this->isReRelease($raw['release_date']),
            );
        })->values()->all();
    }

    private function isReRelease(?string $releaseDate): bool
    {
        if ($releaseDate === null) {
            return false;
        }

        return CarbonImmutable::parse($releaseDate)->lt(now()->subYear());
    }

    /**
     * @param  Collection<int, array{tmdb_id: int, type: TitleType, name: string, year: ?string, poster: ?string, release_date: ?string}>  $rawItems
     * @return Collection<string, Title>
     */
    private function existingTitles(Collection $rawItems): Collection
    {
        $tmdbIds = $rawItems->pluck('tmdb_id')->unique()->all();

        if ($tmdbIds === []) {
            return collect();
        }

        return Title::whereIn('tmdb_id', $tmdbIds)
            ->with(['plays:id,playable_type,playable_id', 'episodes.plays:id,playable_type,playable_id', 'libraryStatus'])
            ->withExists(['mediaLists as on_list', 'follow as is_followed'])
            ->get()
            ->keyBy(fn (Title $title): string => "{$title->type->value}:{$title->tmdb_id}");
    }

    private function isWatched(Title $title): bool
    {
        return $title->isMovie()
            ? $title->plays->isNotEmpty()
            : $title->episodes->pluck('plays')->flatten()->isNotEmpty();
    }

    /**
     * Most recently watched/highly rated titles from the last 90 days, most recent first.
     *
     * @return array<int, int>
     */
    private function seedTitleIds(): array
    {
        $since = now()->subDays(self::SEED_WINDOW_DAYS);

        $moviePlays = Play::query()
            ->where('playable_type', (new Title)->getMorphClass())
            ->where('watched_at', '>=', $since)
            ->get(['playable_id', 'watched_at'])
            ->mapWithKeys(fn (Play $play): array => [$play->playable_id => $play->watched_at]);

        $episodePlays = Play::query()
            ->where('playable_type', (new Episode)->getMorphClass())
            ->where('watched_at', '>=', $since)
            ->with('playable:id,title_id')
            ->get()
            ->filter(fn (Play $play): bool => $play->playable !== null)
            ->groupBy(fn (Play $play): int => $play->playable->title_id)
            ->map(fn (Collection $plays) => $plays->max('watched_at'));

        $highlyRated = Rating::query()
            ->where('rateable_type', (new Title)->getMorphClass())
            ->where('score', '>=', 8)
            ->where('created_at', '>=', $since)
            ->get(['rateable_id', 'created_at'])
            ->mapWithKeys(fn (Rating $rating): array => [$rating->rateable_id => $rating->created_at]);

        $recency = collect();

        foreach ([$moviePlays, $episodePlays, $highlyRated] as $source) {
            foreach ($source as $titleId => $timestamp) {
                if (! $recency->has($titleId) || $timestamp->gt($recency->get($titleId))) {
                    $recency->put($titleId, $timestamp);
                }
            }
        }

        return $recency->sortDesc()->keys()->take(self::MAX_RECOMMENDATION_SHELVES)->all();
    }

    private function posterUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return config('services.tmdb.image_base_url')."/w342{$path}";
    }
}
