<?php

use App\Enums\PlaySource;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\Stats\StatsService;
use Illuminate\Support\Facades\DB;

function makeStatsShow(string $name, array $genres = ['Drama']): array
{
    $show = Title::factory()->show()->create(['name' => $name, 'genres' => $genres]);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);

    return [$show, $season];
}

test('total watch minutes sum movie and episode runtimes, including unknown watched_at', function () {
    $movie = Title::factory()->movie()->create(['runtime' => 120]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => null]);

    [$show, $season] = makeStatsShow('Show');
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'runtime' => 45]);
    Play::factory()->for($episode, 'playable')->create(['watched_at' => now()]);

    $summary = app(StatsService::class)->summary();

    expect($summary['headline']['totalWatchMinutes'])->toBe(165);
});

test('movies and episodes watched are counted once per distinct title', function () {
    $movie = Title::factory()->movie()->create();
    Play::factory()->for($movie, 'playable')->count(2)->create(['watched_at' => now()]);

    [$show, $season] = makeStatsShow('Show');
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1]);
    Play::factory()->for($episode, 'playable')->count(3)->create(['watched_at' => now()]);

    $summary = app(StatsService::class)->summary();

    expect($summary['headline']['moviesWatched'])->toBe(1)
        ->and($summary['headline']['episodesWatched'])->toBe(1);
});

test('year filtering scopes headline totals to plays within that year', function () {
    $movie2024 = Title::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($movie2024, 'playable')->create(['watched_at' => '2024-06-15 12:00:00']);

    $movie2025 = Title::factory()->movie()->create(['runtime' => 50]);
    Play::factory()->for($movie2025, 'playable')->create(['watched_at' => '2025-06-15 12:00:00']);

    $summary = app(StatsService::class)->summary(2024);

    expect($summary['headline']['totalWatchMinutes'])->toBe(100)
        ->and($summary['headline']['moviesWatched'])->toBe(1);
});

test('year filtering uses the user\'s local calendar year, not UTC', function () {
    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $this->actingAs($user);

    // 2026-01-01 02:00 UTC is still 2025-12-31 21:00 in America/New_York (UTC-5).
    $movie = Title::factory()->movie()->create(['runtime' => 90]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => '2026-01-01 02:00:00']);

    $summary2025 = app(StatsService::class)->summary(2025);
    $summary2026 = app(StatsService::class)->summary(2026);

    expect($summary2025['headline']['totalWatchMinutes'])->toBe(90)
        ->and($summary2026['headline']['totalWatchMinutes'])->toBe(0);
});

test('the heatmap skips plays with an unknown watched_at', function () {
    $movie = Title::factory()->movie()->create(['runtime' => 90]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => null]);

    $summary = app(StatsService::class)->summary();

    expect(collect($summary['heatmap'])->flatten()->sum())->toBe(0);
});

test('the heatmap buckets a play by its local weekday and hour, not UTC', function () {
    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $this->actingAs($user);

    // 2026-01-02 (Friday) 02:30 UTC is 2026-01-01 (Thursday) 21:30 in America/New_York.
    $movie = Title::factory()->movie()->create(['runtime' => 60]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => '2026-01-02 02:30:00']);

    $summary = app(StatsService::class)->summary();

    expect($summary['heatmap'][4][21])->toBe(60) // Thursday=4, 21:00 hour
        ->and($summary['heatmap'][5][2])->toBe(0);
});

test('the heatmap accounts for a daylight saving time transition', function () {
    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $this->actingAs($user);

    // The US spring-forward transition on 2026-03-08 jumps local clocks from 2am to 3am at
    // 07:00 UTC. 07:30 UTC therefore lands at 03:30 EDT (UTC-4), not 02:30 EST (UTC-5) — a
    // fixed offset would get this wrong.
    $movie = Title::factory()->movie()->create(['runtime' => 30]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => '2026-03-08 07:30:00']);

    $summary = app(StatsService::class)->summary();

    expect($summary['heatmap'][0][3])->toBe(30) // Sunday=0, 03:00 hour (EDT)
        ->and($summary['heatmap'][0][2])->toBe(0);
});

test('top genres roll up across movies and episodes, but average rating by genre only counts title ratings', function () {
    $horrorMovie = Title::factory()->movie()->create(['genres' => ['Horror']]);
    Play::factory()->for($horrorMovie, 'playable')->create(['watched_at' => now()]);
    Rating::factory()->for($horrorMovie, 'rateable')->create(['score' => 8]);

    [$show, $season] = makeStatsShow('Horror Show', ['Horror', 'Drama']);
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1]);
    Play::factory()->for($episode, 'playable')->create(['watched_at' => now()]);

    $summary = app(StatsService::class)->summary();

    $genreCounts = collect($summary['topGenres'])->pluck('count', 'genre');
    $averageRating = collect($summary['averageRatingByGenre'])->pluck('average', 'genre');

    expect($genreCounts['Horror'])->toBe(2)
        ->and($genreCounts['Drama'])->toBe(1)
        ->and($averageRating['Horror'])->toBe(4.0)
        ->and($averageRating->has('Drama'))->toBeFalse();
});

test('most rewatched combines repeated movie plays and repeated episode plays per show', function () {
    $movie = Title::factory()->movie()->create(['name' => 'Rewatched Movie']);
    Play::factory()->for($movie, 'playable')->count(3)->create(['watched_at' => now()]);

    $onceMovie = Title::factory()->movie()->create(['name' => 'Once Movie']);
    Play::factory()->for($onceMovie, 'playable')->create(['watched_at' => now()]);

    [$show, $season] = makeStatsShow('Rewatched Show');
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1]);
    Play::factory()->for($episode, 'playable')->count(2)->create(['watched_at' => now()]);

    $summary = app(StatsService::class)->summary();

    $names = collect($summary['mostRewatched'])->pluck('name');

    expect($names)->toContain('Rewatched Movie')
        ->toContain('Rewatched Show')
        ->not->toContain('Once Movie');
});

test('sources breakdown counts plays per source', function () {
    $a = Title::factory()->movie()->create();
    Play::factory()->for($a, 'playable')->create(['source' => PlaySource::Plex, 'watched_at' => now()]);

    $b = Title::factory()->movie()->create();
    Play::factory()->for($b, 'playable')->create(['source' => PlaySource::Manual, 'watched_at' => now()]);

    $c = Title::factory()->movie()->create();
    Play::factory()->for($c, 'playable')->create(['source' => PlaySource::Manual, 'watched_at' => now()]);

    $summary = app(StatsService::class)->summary();

    expect($summary['sources'])->toMatchArray(['manual' => 2, 'plex' => 1]);
});

test('current and longest streaks are computed from consecutive play days', function () {
    $movie = Title::factory()->movie()->create();

    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()->subDay()]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()->subDays(2)]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()->subDays(10)]);

    $summary = app(StatsService::class)->summary();

    expect($summary['streaks']['current'])->toBe(3)
        ->and($summary['streaks']['longest'])->toBe(3);
});

test('a streak day is based on the local calendar date, not UTC', function () {
    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $this->actingAs($user);

    // These plays land on two different UTC calendar dates (2026-01-01 and 2026-01-02),
    // but both are 2026-01-01 in America/New_York (UTC-5) — the same local day, so this
    // should count as a single-day streak, not two.
    $movie = Title::factory()->movie()->create();
    Play::factory()->for($movie, 'playable')->create(['watched_at' => '2026-01-01 23:30:00']);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => '2026-01-02 02:30:00']);

    $summary = app(StatsService::class)->summary();

    expect($summary['streaks']['longest'])->toBe(1);
});

test('available years lists distinct years with a play', function () {
    $movie = Title::factory()->movie()->create();
    Play::factory()->for($movie, 'playable')->create(['watched_at' => '2023-05-01 00:00:00']);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => '2024-05-01 00:00:00']);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => null]);

    expect(app(StatsService::class)->availableYears())->toBe([2024, 2023]);
});

test('monthly plays returns 24 months with computed percentages and a nice axis max', function () {
    $movie = Title::factory()->movie()->create();
    Play::factory()->for($movie, 'playable')->count(3)->create(['watched_at' => now()]);

    [$show, $season] = makeStatsShow('Show');
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1]);
    Play::factory()->for($episode, 'playable')->count(2)->create(['watched_at' => now()]);

    $summary = app(StatsService::class)->summary();
    $monthly = $summary['monthly'];

    expect($monthly['months'])->toHaveCount(24);

    $currentMonth = $monthly['months'][23];

    expect($currentMonth['movies'])->toBe(3)
        ->and($currentMonth['episodes'])->toBe(2)
        ->and($currentMonth['total'])->toBe(5)
        ->and($currentMonth['labelDesktop'])->toBeTrue()
        ->and($currentMonth['labelMobile'])->toBeTrue()
        ->and($monthly['maxTotal'])->toBeGreaterThanOrEqual(5)
        ->and($currentMonth['moviesPct'])->toBe(3 / $monthly['maxTotal'] * 100)
        ->and($currentMonth['episodesPct'])->toBe(2 / $monthly['maxTotal'] * 100)
        ->and($monthly['ticks'])->toHaveCount(5)
        ->and($monthly['ticks'][0])->toBe(0)
        ->and($monthly['ticks'][4])->toBe($monthly['maxTotal']);
});

test('monthly plays are null when a year is selected', function () {
    $summary = app(StatsService::class)->summary(2024);

    expect($summary['monthly'])->toBeNull();
});

test('the summary runs a bounded number of queries regardless of dataset size', function () {
    foreach (range(1, 20) as $n) {
        $movie = Title::factory()->movie()->create(['runtime' => 100, 'genres' => ['Drama']]);
        Play::factory()->for($movie, 'playable')->create(['watched_at' => now()->subDays($n)]);
    }

    foreach (range(1, 5) as $n) {
        [$show, $season] = makeStatsShow("Show {$n}");

        foreach (range(1, 10) as $e) {
            $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'runtime' => 30]);
            Play::factory()->for($episode, 'playable')->create(['watched_at' => now()->subDays($e)]);
        }
    }

    DB::enableQueryLog();

    app(StatsService::class)->summary();

    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(25);
});

test('monthlyPlays, heatmap, and streaks aggregate in SQL: query count stays flat as play count grows', function () {
    $movie = Title::factory()->movie()->create(['runtime' => 60]);

    // Spread across the last 30 days so streaks, the heatmap, and the monthly chart all have
    // real work to do; a per-row PHP loop (the old implementation) would scale with this count,
    // a SQL GROUP BY/DISTINCT does not.
    foreach (range(0, 199) as $n) {
        Play::factory()->for($movie, 'playable')->create(['watched_at' => now()->subDays($n % 30)]);
    }

    DB::enableQueryLog();

    $summary = app(StatsService::class)->summary();

    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(25)
        ->and(collect($summary['heatmap'])->flatten()->sum())->toBe(200 * 60)
        ->and(array_sum(array_column($summary['monthly']['months'], 'movies')))->toBe(200)
        ->and($summary['streaks']['longest'])->toBe(30);
});

test('the cached summary holds no objects, so it survives the cache store refusing to unserialize classes', function () {
    config(['cache.serializable_classes' => false]);

    $movie = Title::factory()->movie()->create(['name' => 'Rewatched Movie']);
    Play::factory()->for($movie, 'playable')->count(2)->create(['watched_at' => now()]);

    [$show, $season] = makeStatsShow('Top Show');
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'runtime' => 30]);
    Play::factory()->for($episode, 'playable')->create(['watched_at' => now()]);

    $summary = app(StatsService::class)->summary();

    $objects = [];
    array_walk_recursive($summary, function (mixed $value) use (&$objects): void {
        if (is_object($value)) {
            $objects[] = $value::class;
        }
    });

    expect($objects)->toBe([])
        ->and($summary['topShows']['byEpisodes'][0]['name'])->toBe('Top Show')
        ->and($summary['mostRewatched'][0]['name'])->toBe('Rewatched Movie');
});
