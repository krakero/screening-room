<?php

use App\Actions\Tmdb\RefreshSeasonTrailer;
use App\Models\Season;
use App\Models\Title;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function seasonTrailerVideosFixture(string $key): array
{
    return [
        'results' => [
            [
                'site' => 'YouTube',
                'type' => 'Trailer',
                'key' => $key,
                'name' => 'Season Trailer',
                'official' => true,
                'iso_639_1' => 'en',
                'published_at' => '2020-01-01T00:00:00.000Z',
            ],
        ],
    ];
}

test('it stores the trailer and sets trailer_checked_at', function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'trailer_key' => null]);

    Http::fake([
        '*/tv/1396/season/1/videos*' => Http::response(seasonTrailerVideosFixture('season-key')),
    ]);

    app(RefreshSeasonTrailer::class)->handle($season);

    expect($season->fresh()->trailer_site)->toBe('YouTube')
        ->and($season->fresh()->trailer_key)->toBe('season-key')
        ->and($season->fresh()->trailer_checked_at)->not->toBeNull();
});

test('it sets trailer_checked_at even when TMDB has no trailer', function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'trailer_key' => null]);

    Http::fake([
        '*/tv/1396/season/1/videos*' => Http::response(['results' => []]),
    ]);

    app(RefreshSeasonTrailer::class)->handle($season);

    expect($season->fresh()->trailer_key)->toBeNull()
        ->and($season->fresh()->trailer_checked_at)->not->toBeNull();
});

test('it leaves trailer_checked_at null when TMDB fails', function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'trailer_key' => null]);

    Http::fake([
        '*/tv/1396/season/1/videos*' => Http::response(null, 500),
    ]);

    app(RefreshSeasonTrailer::class)->handle($season);

    expect($season->fresh()->trailer_checked_at)->toBeNull();
});

test('it does nothing when TMDB is not configured', function () {
    config(['services.tmdb.token' => null]);

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'trailer_key' => null]);

    app(RefreshSeasonTrailer::class)->handle($season);

    Http::assertNothingSent();
    expect($season->fresh()->trailer_checked_at)->toBeNull();
});
