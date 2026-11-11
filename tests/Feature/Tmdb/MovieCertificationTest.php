<?php

use App\Actions\Tmdb\ImportMovie;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
    Http::preventStrayRequests();
});

function movieFixtureWithReleaseDates(array $results): array
{
    $base = json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/movie_603.json')),
        true,
    );
    $base['release_dates'] = ['results' => $results];

    return $base;
}

test('certification is parsed from release_dates for the configured region', function () {
    config(['services.tmdb.region' => 'US']);

    $fixture = movieFixtureWithReleaseDates([
        ['iso_3166_1' => 'US', 'release_dates' => [
            ['certification' => 'R', 'type' => 3, 'release_date' => '1999-03-30T00:00:00.000Z'],
        ]],
    ]);

    Http::fake(['*/movie/603*' => Http::response($fixture)]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->certification)->toBe('R');
});

test('certification falls back to US when configured region has no entry', function () {
    config(['services.tmdb.region' => 'AU']);

    $fixture = movieFixtureWithReleaseDates([
        ['iso_3166_1' => 'US', 'release_dates' => [
            ['certification' => 'R', 'type' => 3, 'release_date' => '1999-03-30T00:00:00.000Z'],
        ]],
    ]);

    Http::fake(['*/movie/603*' => Http::response($fixture)]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->certification)->toBe('R');
});

test('certification prefers theatrical type 3 over other types', function () {
    config(['services.tmdb.region' => 'US']);

    $fixture = movieFixtureWithReleaseDates([
        ['iso_3166_1' => 'US', 'release_dates' => [
            ['certification' => 'NR', 'type' => 1, 'release_date' => '1999-01-01T00:00:00.000Z'],
            ['certification' => 'R', 'type' => 3, 'release_date' => '1999-03-30T00:00:00.000Z'],
        ]],
    ]);

    Http::fake(['*/movie/603*' => Http::response($fixture)]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->certification)->toBe('R');
});

test('certification is null when release_dates is missing', function () {
    config(['services.tmdb.region' => 'US']);

    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/movie_603.json')),
        true,
    );
    unset($fixture['release_dates']);

    Http::fake(['*/movie/603*' => Http::response($fixture)]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->certification)->toBeNull();
});

test('certification is null when no matching region and no US fallback', function () {
    config(['services.tmdb.region' => 'AU']);

    $fixture = movieFixtureWithReleaseDates([
        ['iso_3166_1' => 'GB', 'release_dates' => [
            ['certification' => '15', 'type' => 3, 'release_date' => '1999-04-01T00:00:00.000Z'],
        ]],
    ]);

    Http::fake(['*/movie/603*' => Http::response($fixture)]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->certification)->toBeNull();
});

test('director is parsed from crew members with job Director', function () {
    config(['services.tmdb.region' => 'US']);

    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/movie_603.json')),
        true,
    );

    Http::fake(['*/movie/603*' => Http::response($fixture)]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->director)->toBe('Lana Wachowski');
});

test('director joins multiple directors with comma', function () {
    config(['services.tmdb.region' => 'US']);

    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/movie_603.json')),
        true,
    );
    $fixture['credits']['crew'][] = ['id' => 9341, 'name' => 'Lilly Wachowski', 'job' => 'Director', 'department' => 'Directing', 'profile_path' => null];

    Http::fake(['*/movie/603*' => Http::response($fixture)]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->director)->toBe('Lana Wachowski, Lilly Wachowski');
});

test('director is null when no crew member has job Director', function () {
    config(['services.tmdb.region' => 'US']);

    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/movie_603.json')),
        true,
    );
    $fixture['credits']['crew'] = array_filter(
        $fixture['credits']['crew'],
        fn (array $m): bool => $m['job'] !== 'Director',
    );

    Http::fake(['*/movie/603*' => Http::response($fixture)]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->director)->toBeNull();
});
