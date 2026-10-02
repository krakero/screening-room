<?php

use App\Models\Play;
use App\Models\Title;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();

    Http::fake([
        '*/movie/1001*' => Http::response(['id' => 1001, 'title' => 'Test Movie One', 'credits' => ['cast' => [], 'crew' => []]]),
        '*/movie/1002*' => Http::response(['status_message' => 'not found'], 404),
        '*/movie/1003*' => Http::response(['id' => 1003, 'title' => 'Test Movie Three', 'credits' => ['cast' => [], 'crew' => []]]),
        '*/tv/2001*' => Http::response([
            'id' => 2001,
            'name' => 'Test Show One',
            'seasons' => [['season_number' => 1]],
            'aggregate_credits' => ['cast' => [], 'crew' => []],
            'season/1' => ['id' => 9001, 'name' => 'Season 1', 'episodes' => [
                ['id' => 700001, 'episode_number' => 1, 'season_number' => 1],
                ['id' => 700002, 'episode_number' => 2, 'season_number' => 1],
            ]],
        ]),
    ]);
});

test('it dry-run imports and reports counts without writing', function () {
    $this->artisan('trakt:import', [
        'path' => base_path('tests/Fixtures/trakt/sample'),
        '--dry-run' => true,
    ])->assertExitCode(0);

    expect(Title::count())->toBe(0);
});

test('it imports for real and writes to the database', function () {
    $this->artisan('trakt:import', [
        'path' => base_path('tests/Fixtures/trakt/sample'),
    ])->assertExitCode(0);

    expect(Title::count())->toBe(3)
        ->and(Play::count())->toBe(3);
});

test('it rejects an unknown path', function () {
    $this->artisan('trakt:import', [
        'path' => '/nonexistent/'.uniqid(),
    ])->assertExitCode(1);

    Http::assertNothingSent();
});

test('it validates the --only option', function () {
    $this->artisan('trakt:import', [
        'path' => base_path('tests/Fixtures/trakt/sample'),
        '--only' => 'history,bogus',
    ])->assertExitCode(2);
});
