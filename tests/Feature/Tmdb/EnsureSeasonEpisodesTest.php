<?php

use App\Actions\Tmdb\EnsureSeasonEpisodes;
use App\Enums\TitleType;
use App\Jobs\ImportSeasonEpisodes;
use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

function seasonOnePayload(): array
{
    return [
        'id' => 1396,
        'season/1' => json_decode(
            file_get_contents(base_path('tests/Fixtures/tmdb/show_1396_seasons.json')),
            true,
        )['season/1'],
    ];
}

test('it imports episodes when the season has never been synced', function () {
    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);

    Http::fake(['*/tv/1396*' => Http::response(seasonOnePayload())]);

    app(EnsureSeasonEpisodes::class)->handle($season);

    expect(Episode::where('season_id', $season->id)->count())->toBe(2);
});

test('it no-ops when the season was synced recently', function () {
    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'episodes_synced_at' => now()]);

    Http::fake();

    app(EnsureSeasonEpisodes::class)->handle($season);

    Http::assertNothingSent();
});

test('it re-imports when the season sync is stale', function () {
    config(['showing.episode_refresh_days' => 1]);

    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'episodes_synced_at' => now()->subDays(2)]);

    Http::fake(['*/tv/1396*' => Http::response(seasonOnePayload())]);

    app(EnsureSeasonEpisodes::class)->handle($season);

    Http::assertSentCount(1);
    expect($season->fresh()->episodes_synced_at->isToday())->toBeTrue();
});

test('it locks per season so a concurrent caller does not double-import', function () {
    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);

    $lock = Cache::lock("ensure-season-episodes:{$season->id}", 30);
    $lock->get();

    Http::fake();

    app(EnsureSeasonEpisodes::class)->handle($season);

    Http::assertNothingSent();

    $lock->release();
});

test('dispatchRefreshIfStale queues an import job instead of syncing inline when stale', function () {
    Queue::fake();

    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);

    Http::fake();

    app(EnsureSeasonEpisodes::class)->dispatchRefreshIfStale($season);

    Http::assertNothingSent();
    Queue::assertPushed(ImportSeasonEpisodes::class, fn (ImportSeasonEpisodes $job) => $job->titleId === $title->id && $job->seasonNumber === 1);
});

test('a stale re-import overwrites a placeholder name and missing still with real TMDB data', function () {
    config(['showing.episode_refresh_days' => 1]);

    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'episodes_synced_at' => now()->subDays(2)]);

    // Simulate an early import that only had a TMDB placeholder name and no still image yet.
    Episode::factory()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 1,
        'tmdb_id' => 62085,
        'name' => 'Episode 1',
        'still_path' => null,
    ]);

    Http::fake(['*/tv/1396*' => Http::response(seasonOnePayload())]);

    app(EnsureSeasonEpisodes::class)->handle($season);

    $episode = Episode::where('tmdb_id', 62085)->firstOrFail();
    expect($episode->name)->toBe('Pilot')
        ->and($episode->still_path)->toBe('/3P4dqcRk1FvyKF1P1eBBGqfa2j.jpg');
});

test('dispatchRefreshIfStale does nothing when the season was synced recently', function () {
    Queue::fake();

    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'episodes_synced_at' => now()]);

    app(EnsureSeasonEpisodes::class)->dispatchRefreshIfStale($season);

    Queue::assertNotPushed(ImportSeasonEpisodes::class);
});
