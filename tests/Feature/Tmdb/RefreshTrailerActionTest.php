<?php

use App\Actions\Tmdb\RefreshTrailer;
use App\Models\Title;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function trailerVideosFixture(string $key): array
{
    return [
        'results' => [
            [
                'site' => 'YouTube',
                'type' => 'Trailer',
                'key' => $key,
                'name' => 'Trailer',
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

    $title = Title::factory()->movie()->create(['tmdb_id' => 603, 'trailer_key' => null]);

    Http::fake([
        '*/movie/603/videos*' => Http::response(trailerVideosFixture('found-key')),
    ]);

    app(RefreshTrailer::class)->handle($title);

    expect($title->fresh()->trailer_site)->toBe('YouTube')
        ->and($title->fresh()->trailer_key)->toBe('found-key')
        ->and($title->fresh()->trailer_checked_at)->not->toBeNull();
});

test('it sets trailer_checked_at even when TMDB has no trailer', function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    $title = Title::factory()->show()->create(['tmdb_id' => 1396, 'trailer_key' => null]);

    Http::fake([
        '*/tv/1396/videos*' => Http::response(['results' => []]),
    ]);

    app(RefreshTrailer::class)->handle($title);

    expect($title->fresh()->trailer_key)->toBeNull()
        ->and($title->fresh()->trailer_checked_at)->not->toBeNull();
});

test('it leaves trailer_checked_at null when TMDB fails', function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603, 'trailer_key' => null]);

    Http::fake([
        '*/movie/603/videos*' => Http::response(null, 500),
    ]);

    app(RefreshTrailer::class)->handle($title);

    expect($title->fresh()->trailer_checked_at)->toBeNull();
});

test('it does nothing when TMDB is not configured', function () {
    config(['services.tmdb.token' => null]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603, 'trailer_key' => null]);

    app(RefreshTrailer::class)->handle($title);

    Http::assertNothingSent();
    expect($title->fresh()->trailer_checked_at)->toBeNull();
});
