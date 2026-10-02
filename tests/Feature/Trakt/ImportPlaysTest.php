<?php

use App\Actions\Trakt\ImportPlays;
use App\Enums\PlaySource;
use App\Enums\TitleType;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Services\Trakt\ExportReader;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->reader = new ExportReader(base_path('tests/Fixtures/trakt/sample'));

    $movie = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 1001]);
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 2001]);
    $season = Season::factory()->create(['title_id' => $show->id, 'season_number' => 1]);
    Episode::factory()->create(['title_id' => $show->id, 'season_id' => $season->id, 'season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->create(['title_id' => $show->id, 'season_id' => $season->id, 'season_number' => 1, 'episode_number' => 2]);

    $this->movie = $movie;
});

test('it creates plays for resolvable history entries and skips the rest', function () {
    $result = app(ImportPlays::class)->handle($this->reader->history(), dryRun: false);

    expect($result->imported)->toBe(3)
        ->and($result->skipped)->toBe(2);

    expect(Play::count())->toBe(3);

    $moviePlay = Play::where('source', PlaySource::Trakt)->where('external_id', '5001')->first();
    expect($moviePlay)->not->toBeNull()
        ->and($moviePlay->playable_id)->toBe($this->movie->id)
        ->and($moviePlay->watched_at->toIso8601String())->toBe('2024-01-01T00:00:00+00:00');
});

test('re-running the import is idempotent', function () {
    app(ImportPlays::class)->handle($this->reader->history(), dryRun: false);
    app(ImportPlays::class)->handle($this->reader->history(), dryRun: false);

    expect(Play::count())->toBe(3);
});

test('dry run makes no writes', function () {
    $result = app(ImportPlays::class)->handle($this->reader->history(), dryRun: true);

    expect($result->imported)->toBe(3)
        ->and($result->skipped)->toBe(2)
        ->and(Play::count())->toBe(0);
});

test('it ensures a show\'s season episodes are loaded before matching plays against them, instead of silently skipping them', function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
    Http::preventStrayRequests();

    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 3001]);
    $season = Season::factory()->notLoaded()->create(['title_id' => $show->id, 'season_number' => 1]);

    Http::fake([
        '*/tv/3001*' => Http::response([
            'id' => 3001,
            'season/1' => [
                'id' => 9001,
                'season_number' => 1,
                'episodes' => [
                    ['id' => 800001, 'episode_number' => 1, 'season_number' => 1, 'name' => 'Pilot'],
                ],
            ],
        ]),
    ]);

    $entries = [
        ['id' => 9999, 'watched_at' => '2024-02-01T00:00:00Z', 'type' => 'episode', 'show' => ['ids' => ['tmdb' => 3001]], 'episode' => ['season' => 1, 'number' => 1]],
    ];

    $result = app(ImportPlays::class)->handle($entries, dryRun: false);

    expect($result->imported)->toBe(1)
        ->and($result->skipped)->toBe(0)
        ->and(Episode::where('season_id', $season->id)->count())->toBe(1)
        ->and(Play::where('external_id', '9999')->exists())->toBeTrue()
        ->and($season->fresh()->episodesLoaded())->toBeTrue();
});
