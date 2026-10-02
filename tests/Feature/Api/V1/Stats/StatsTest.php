<?php

use App\Models\Play;
use App\Models\Title;
use App\Models\User;
use App\Services\Stats\StatsService;
use Illuminate\Support\Facades\DB;

test('stats requires authentication', function () {
    $this->getJson('/api/v1/stats')->assertUnauthorized();
});

test('stats years requires authentication', function () {
    $this->getJson('/api/v1/stats/years')->assertUnauthorized();
});

test('stats returns the full summary shape for all time', function () {
    $user = User::factory()->create();
    $movie = Title::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats');

    $response->assertOk();
    $response->assertJsonStructure([
        'headline' => ['totalWatchMinutes', 'moviesWatched', 'episodesWatched', 'showsFollowed', 'showsCompleted', 'playsThisYear'],
        'monthly' => ['months', 'maxTotal', 'ticks'],
        'heatmap',
        'topShows' => ['byEpisodes', 'byHours'],
        'topGenres',
        'averageRatingByGenre',
        'mostRewatched',
        'sources',
        'streaks' => ['current', 'longest'],
    ]);
    expect($response->json('headline.totalWatchMinutes'))->toBe(100);
});

test('stats with a year filter nulls out monthly and streaks', function () {
    $user = User::factory()->create();
    $movie = Title::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()->setYear(2025)]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats?year=2025');

    $response->assertOk();
    expect($response->json('monthly'))->toBeNull();
    expect($response->json('streaks'))->toBeNull();
    expect($response->json('headline.totalWatchMinutes'))->toBe(100);
});

test('stats rejects a malformed year', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats?year=abc')
        ->assertUnprocessable();
});

test('stats years lists the years with recorded plays, most recent first', function () {
    $user = User::factory()->create();
    $movie = Title::factory()->movie()->create();
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()->setYear(2024)]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()->setYear(2026)]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats/years');

    $response->assertOk()->assertJson(['data' => [2026, 2024]]);
});

test('a repeated stats call reuses the cached payload instead of recomputing it', function () {
    $user = User::factory()->create();
    $movie = Title::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);

    app(StatsService::class)->summary();

    DB::enableQueryLog();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/stats')->assertOk();

    $queriedPlays = collect(DB::getQueryLog())->contains(
        fn (array $entry): bool => str_contains(strtolower((string) $entry['query']), 'from `plays`'),
    );

    DB::disableQueryLog();

    expect($queriedPlays)->toBeFalse();
});
