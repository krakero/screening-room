<?php

use App\Actions\Trakt\ImportPlays;
use App\Enums\FollowState;
use App\Enums\PlaySource;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Title;
use App\Services\Stats\StatsService;
use Illuminate\Support\Facades\DB;

test('creating a play invalidates the cached summary', function () {
    $movie = Title::factory()->movie()->create(['runtime' => 100]);

    $summary = app(StatsService::class)->summary();
    expect($summary['headline']['totalWatchMinutes'])->toBe(0);

    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);

    $summary = app(StatsService::class)->summary();
    expect($summary['headline']['totalWatchMinutes'])->toBe(100);
});

test('deleting a play invalidates the cached summary', function () {
    $movie = Title::factory()->movie()->create(['runtime' => 100]);
    $play = Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);

    $summary = app(StatsService::class)->summary();
    expect($summary['headline']['totalWatchMinutes'])->toBe(100);

    $play->delete();

    $summary = app(StatsService::class)->summary();
    expect($summary['headline']['totalWatchMinutes'])->toBe(0);
});

test('a bulk import path invalidates the cached summary', function () {
    $movie = Title::factory()->movie()->create(['runtime' => 100, 'tmdb_id' => 42]);

    $summary = app(StatsService::class)->summary();
    expect($summary['headline']['moviesWatched'])->toBe(0);

    app(ImportPlays::class)->handle([
        ['type' => 'movie', 'id' => 1, 'watched_at' => now()->toIso8601String(), 'movie' => ['ids' => ['tmdb' => 42]]],
    ], dryRun: false);

    $summary = app(StatsService::class)->summary();
    expect($summary['headline']['moviesWatched'])->toBe(1);
});

test('a rating change invalidates the cached summary', function () {
    $movie = Title::factory()->movie()->create(['genres' => ['Horror']]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);

    $summary = app(StatsService::class)->summary();
    expect($summary['averageRatingByGenre'])->toBe([]);

    Rating::factory()->for($movie, 'rateable')->create(['score' => 8]);

    $summary = app(StatsService::class)->summary();
    expect(collect($summary['averageRatingByGenre'])->pluck('count', 'genre')->get('Horror'))->toBe(1);
});

test('a follow change invalidates the cached summary', function () {
    $show = Title::factory()->show()->create();

    $summary = app(StatsService::class)->summary();
    expect($summary['headline']['showsFollowed'])->toBe(0);

    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $summary = app(StatsService::class)->summary();
    expect($summary['headline']['showsFollowed'])->toBe(1);
});

test('the cache is reused when nothing changed', function () {
    $movie = Title::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now(), 'source' => PlaySource::Manual]);

    app(StatsService::class)->summary();

    DB::enableQueryLog();

    app(StatsService::class)->summary();

    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queryCount)->toBe(0);
});
