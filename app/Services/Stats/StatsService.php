<?php

namespace App\Services\Stats;

use App\Enums\FollowState;
use App\Models\Follow;
use App\Models\Play;
use App\Support\DisplayTimezone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsService
{
    /**
     * Bump whenever the shape of the cached summary payload changes, so entries cached by
     * older code are never read back by newer views.
     */
    private const PAYLOAD_VERSION = 3;

    public function __construct(private readonly StatsCacheVersion $version) {}

    /**
     * Build the full stats payload for the given year, or all time when null.
     *
     * @return array<string, mixed>
     */
    public function summary(?int $year = null): array
    {
        $key = 'stats:summary:'.($year ?? 'all').':p'.self::PAYLOAD_VERSION.':v'.$this->version->current();

        return Cache::remember($key, now()->addDay(), fn (): array => $this->build($year));
    }

    /**
     * Fill the caches the Stats page reads on first load: the year list and the all-time summary.
     */
    public function warm(): void
    {
        $this->availableYears();
        $this->summary();
    }

    /**
     * @return array<int, int>
     */
    public function availableYears(): array
    {
        return Cache::remember(
            'stats:years:v'.$this->version->current(),
            now()->addDay(),
            fn (): array => DB::table('plays')
                ->whereNotNull('watched_at')
                ->selectRaw('DISTINCT YEAR('.DisplayTimezone::sqlLocal('watched_at').') as y')
                ->orderByDesc('y')
                ->pluck('y')
                ->map(fn (mixed $year): int => (int) $year)
                ->values()
                ->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(?int $year): array
    {
        return [
            'headline' => $this->headline($year),
            'monthly' => $year === null ? $this->monthlyPlays() : null,
            'heatmap' => $this->heatmap($year),
            'topShows' => $this->topShows($year),
            'topGenres' => $this->topGenres($year),
            'averageRatingByGenre' => $this->averageRatingByGenre($year),
            'mostRewatched' => $this->mostRewatched($year),
            'sources' => $this->sourcesBreakdown($year),
            'streaks' => $year === null ? $this->streaks() : null,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function headline(?int $year): array
    {
        $movieMinutes = $this->moviePlaysQuery($year)->sum('t.runtime');
        $episodeMinutes = $this->episodePlaysQuery($year)->sum('e.runtime');

        return [
            'totalWatchMinutes' => (int) ($movieMinutes + $episodeMinutes),
            'moviesWatched' => (int) $this->moviePlaysQuery($year)->distinct()->count('t.id'),
            'episodesWatched' => (int) $this->episodePlaysQuery($year)->distinct()->count('e.id'),
            'showsFollowed' => Follow::query()->count(),
            'showsCompleted' => Follow::query()->where('state', FollowState::Completed)->count(),
            'playsThisYear' => Play::query()->whereBetween('watched_at', DisplayTimezone::yearRangeUtc(DisplayTimezone::today()->year))->count(),
        ];
    }

    /**
     * @return array{months: array<int, array{month: string, movies: int, episodes: int, total: int, moviesPct: float, episodesPct: float, labelDesktop: bool, labelMobile: bool}>, maxTotal: int, ticks: array<int, int>}
     */
    private function monthlyPlays(): array
    {
        $since = DisplayTimezone::today()->subMonths(23)->startOfMonth();
        $sinceUtc = $since->clone()->utc();
        $local = DisplayTimezone::sqlLocal('plays.watched_at');

        $movies = DB::table('plays')
            ->join('titles as t', fn ($j) => $j->on('t.id', '=', 'plays.playable_id')->where('plays.playable_type', 'title'))
            ->where('plays.watched_at', '>=', $sinceUtc)
            ->selectRaw("DATE_FORMAT({$local}, '%Y-%m') as ym, COUNT(*) as c")
            ->groupBy('ym')
            ->pluck('c', 'ym');

        $episodes = DB::table('plays')
            ->join('episodes as e', fn ($j) => $j->on('e.id', '=', 'plays.playable_id')->where('plays.playable_type', 'episode'))
            ->where('plays.watched_at', '>=', $sinceUtc)
            ->selectRaw("DATE_FORMAT({$local}, '%Y-%m') as ym, COUNT(*) as c")
            ->groupBy('ym')
            ->pluck('c', 'ym');

        $raw = [];
        $cursor = $since->clone();

        for ($i = 0; $i < 24; $i++) {
            $ym = $cursor->format('Y-m');

            $raw[] = [
                'month' => $cursor->format('M Y'),
                'movies' => (int) ($movies[$ym] ?? 0),
                'episodes' => (int) ($episodes[$ym] ?? 0),
            ];

            $cursor->addMonthNoOverflow();
        }

        $maxTotal = $this->niceAxisMax(collect($raw)->map(fn (array $m): int => $m['movies'] + $m['episodes'])->max() ?? 0);
        $lastIndex = count($raw) - 1;

        $months = array_map(function (array $month, int $i) use ($maxTotal, $lastIndex): array {
            $fromEnd = $lastIndex - $i;

            return [
                'month' => $month['month'],
                'movies' => $month['movies'],
                'episodes' => $month['episodes'],
                'total' => $month['movies'] + $month['episodes'],
                'moviesPct' => $maxTotal > 0 ? min(100, ($month['movies'] / $maxTotal) * 100) : 0.0,
                'episodesPct' => $maxTotal > 0 ? min(100, ($month['episodes'] / $maxTotal) * 100) : 0.0,
                'labelDesktop' => $fromEnd % 3 === 0,
                'labelMobile' => $fromEnd % 6 === 0,
            ];
        }, $raw, array_keys($raw));

        return [
            'months' => $months,
            'maxTotal' => $maxTotal,
            'ticks' => $this->axisTicks($maxTotal),
        ];
    }

    /**
     * Round a raw maximum up to a "nice" number (1/2/2.5/5/10 × a power of ten) for a readable axis.
     */
    private function niceAxisMax(int $rawMax): int
    {
        if ($rawMax <= 0) {
            return 4;
        }

        $magnitude = 10 ** floor(log10($rawMax));

        foreach ([1, 2, 2.5, 5, 10] as $step) {
            $candidate = $step * $magnitude;

            if ($candidate >= $rawMax) {
                return (int) ceil($candidate);
            }
        }

        return (int) ceil($rawMax);
    }

    /**
     * @return array<int, int>
     */
    private function axisTicks(int $maxTotal): array
    {
        $steps = 4;

        return array_map(
            fn (int $i): int => (int) round($maxTotal * $i / $steps),
            range(0, $steps),
        );
    }

    /**
     * 7×24 grid of minutes watched, keyed [dayOfWeek 0=Sun..6=Sat][hour 0-23].
     *
     * @return array<int, array<int, int>>
     */
    private function heatmap(?int $year): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $local = DisplayTimezone::sqlLocal('plays.watched_at');

        // MySQL's DAYOFWEEK() returns 1 (Sunday) .. 7 (Saturday); subtracting 1 matches
        // Carbon's dayOfWeek (0 = Sunday .. 6 = Saturday), the indexing $grid already uses.
        $rows = $this->moviePlaysQuery($year)
            ->whereNotNull('plays.watched_at')
            ->selectRaw("DAYOFWEEK({$local}) - 1 as dow, HOUR({$local}) as hour, SUM(t.runtime) as minutes")
            ->groupBy('dow', 'hour')
            ->get()
            ->concat(
                $this->episodePlaysQuery($year)
                    ->whereNotNull('plays.watched_at')
                    ->selectRaw("DAYOFWEEK({$local}) - 1 as dow, HOUR({$local}) as hour, SUM(e.runtime) as minutes")
                    ->groupBy('dow', 'hour')
                    ->get()
            );

        foreach ($rows as $row) {
            $grid[(int) $row->dow][(int) $row->hour] += (int) $row->minutes;
        }

        return $grid;
    }

    /**
     * @return Collection<int, object{id: int, name: string, poster_path: ?string, episode_plays: int, minutes: int}>
     */
    private function showRows(?int $year): Collection
    {
        return $this->episodePlaysQuery($year)
            ->selectRaw('t.id as id, t.name as name, t.poster_path as poster_path, COUNT(*) as episode_plays, SUM(e.runtime) as minutes')
            ->groupBy('t.id', 't.name', 't.poster_path')
            ->get();
    }

    /**
     * Plain arrays, not query-row objects: the summary is cached and the cache store
     * refuses to unserialize objects (`serializable_classes` is false).
     *
     * @return array{byEpisodes: array<int, array{id: int, name: string, poster_path: ?string, episode_plays: int, minutes: int}>, byHours: array<int, array{id: int, name: string, poster_path: ?string, episode_plays: int, minutes: int}>}
     */
    private function topShows(?int $year): array
    {
        $rows = $this->showRows($year)->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'poster_path' => $row->poster_path,
            'episode_plays' => (int) $row->episode_plays,
            'minutes' => (int) $row->minutes,
        ]);

        return [
            'byEpisodes' => $rows->sortByDesc('episode_plays')->take(10)->values()->all(),
            'byHours' => $rows->sortByDesc('minutes')->take(10)->values()->all(),
        ];
    }

    /**
     * @return array<int, array{genre: string, count: int}>
     */
    private function topGenres(?int $year): array
    {
        $movieCounts = $this->moviePlaysQuery($year)
            ->selectRaw('t.id as id, t.genres as genres, COUNT(*) as c')
            ->groupBy('t.id', 't.genres')
            ->get();

        $showCounts = $this->episodePlaysQuery($year)
            ->selectRaw('t.id as id, t.genres as genres, COUNT(*) as c')
            ->groupBy('t.id', 't.genres')
            ->get();

        $genreCounts = [];

        foreach ($movieCounts->concat($showCounts) as $row) {
            foreach (json_decode((string) $row->genres, true) ?? [] as $genre) {
                $genreCounts[$genre] = ($genreCounts[$genre] ?? 0) + (int) $row->c;
            }
        }

        arsort($genreCounts);

        return collect($genreCounts)
            ->map(fn (int $count, string $genre): array => ['genre' => $genre, 'count' => $count])
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{genre: string, average: float, count: int}>
     */
    private function averageRatingByGenre(?int $year): array
    {
        $titleRatings = DB::table('ratings')
            ->join('titles as t', fn ($j) => $j->on('t.id', '=', 'ratings.rateable_id')->where('ratings.rateable_type', 'title'))
            ->whereNotNull('ratings.score')
            ->when($year, fn ($q) => $q->whereBetween('ratings.created_at', DisplayTimezone::yearRangeUtc($year)))
            ->get(['t.genres as genres', 'ratings.score as score']);

        $genreScores = [];

        foreach ($titleRatings as $row) {
            $stars = (int) $row->score / 2;

            foreach (json_decode((string) $row->genres, true) ?? [] as $genre) {
                $genreScores[$genre][] = $stars;
            }
        }

        $genreAverages = collect($genreScores)
            ->map(fn (array $scores, string $genre): array => [
                'genre' => $genre,
                'average' => round(array_sum($scores) / count($scores), 1),
                'count' => count($scores),
            ]);

        return $genreAverages
            ->sortByDesc('average')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Plain arrays for the same cache reason as topShows().
     *
     * @return array<int, array{id: int, name: string, poster_path: ?string, plays: int}>
     */
    private function mostRewatched(?int $year): array
    {
        $movies = $this->moviePlaysQuery($year)
            ->selectRaw('t.id as id, t.name as name, t.poster_path as poster_path, COUNT(*) as plays')
            ->groupBy('t.id', 't.name', 't.poster_path')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $showRewatches = $this->episodePlaysQuery($year)
            ->selectRaw('e.id as episode_id, t.id as id, t.name as name, t.poster_path as poster_path, COUNT(*) as plays')
            ->groupBy('e.id', 't.id', 't.name', 't.poster_path')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->groupBy('id')
            ->map(fn (Collection $group): array => [
                'id' => (int) $group->first()->id,
                'name' => $group->first()->name,
                'poster_path' => $group->first()->poster_path,
                'plays' => (int) $group->sum('plays'),
            ])
            ->values();

        $movies = $movies->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'poster_path' => $row->poster_path,
            'plays' => (int) $row->plays,
        ]);

        return $movies->concat($showRewatches)
            ->sortByDesc('plays')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function sourcesBreakdown(?int $year): array
    {
        return Play::query()
            ->when($year, fn ($q) => $q->whereBetween('watched_at', DisplayTimezone::yearRangeUtc($year)))
            ->selectRaw('source, COUNT(*) as c')
            ->groupBy('source')
            ->pluck('c', 'source')
            ->all();
    }

    /**
     * @return array{current: int, longest: int}
     */
    private function streaks(): array
    {
        $local = DisplayTimezone::sqlLocal('watched_at');

        $dates = DB::table('plays')
            ->whereNotNull('watched_at')
            ->selectRaw("DISTINCT DATE({$local}) as d")
            ->orderBy('d')
            ->pluck('d')
            ->map(fn (string $date): Carbon => Carbon::parse($date, DisplayTimezone::current())->startOfDay())
            ->values();

        if ($dates->isEmpty()) {
            return ['current' => 0, 'longest' => 0];
        }

        $longest = 1;
        $run = 1;
        $previous = $dates->first();

        foreach ($dates->slice(1) as $date) {
            if ((int) $previous->diffInDays($date) === 1) {
                $run++;
            } else {
                $run = 1;
            }

            $longest = max($longest, $run);
            $previous = $date;
        }

        $last = $dates->last();
        $today = DisplayTimezone::today();
        $current = 0;

        if ($last->isSameDay($today) || $last->isSameDay($today->copy()->subDay())) {
            $current = 1;
            $cursor = $last;

            for ($i = $dates->count() - 2; $i >= 0; $i--) {
                $date = $dates[$i];

                if ((int) $date->diffInDays($cursor) === 1) {
                    $current++;
                    $cursor = $date;
                } else {
                    break;
                }
            }
        }

        return ['current' => $current, 'longest' => $longest];
    }

    private function moviePlaysQuery(?int $year): Builder
    {
        return DB::table('plays')
            ->join('titles as t', fn ($j) => $j->on('t.id', '=', 'plays.playable_id')->where('plays.playable_type', 'title'))
            ->when($year, fn ($q) => $q->whereBetween('plays.watched_at', DisplayTimezone::yearRangeUtc($year)));
    }

    private function episodePlaysQuery(?int $year): Builder
    {
        return DB::table('plays')
            ->join('episodes as e', fn ($j) => $j->on('e.id', '=', 'plays.playable_id')->where('plays.playable_type', 'episode'))
            ->join('titles as t', 't.id', '=', 'e.title_id')
            ->when($year, fn ($q) => $q->whereBetween('plays.watched_at', DisplayTimezone::yearRangeUtc($year)));
    }
}
