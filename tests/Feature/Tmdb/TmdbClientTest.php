<?php

use App\Enums\TitleType;
use App\Services\Tmdb\TmdbClient;
use App\Services\Tmdb\TmdbException;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

function tmdbFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/Fixtures/tmdb/{$name}.json")),
        true,
    );
}

test('search sends a bearer auth header', function () {
    Http::fake([
        '*/search/multi*' => Http::response(tmdbFixture('search_multi')),
    ]);

    app(TmdbClient::class)->search('matrix');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('search filters results to movie and tv media types', function () {
    Http::fake([
        '*/search/multi*' => Http::response(tmdbFixture('search_multi')),
    ]);

    $data = app(TmdbClient::class)->search('matrix', 2);

    expect($data['results'])->toHaveCount(2)
        ->and(collect($data['results'])->pluck('media_type')->all())->toBe(['movie', 'tv']);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.themoviedb.org/3/search/multi?query=matrix&page=2');
});

test('movie appends credits, external_ids, videos, and watch/providers', function () {
    Http::fake([
        '*/movie/603*' => Http::response(tmdbFixture('movie_603')),
    ]);

    $data = app(TmdbClient::class)->movie(603);

    expect($data['id'])->toBe(603)
        ->and($data['credits']['cast'])->toHaveCount(2)
        ->and($data['external_ids']['imdb_id'])->toBe('tt0133093');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'append_to_response=credits%2Cexternal_ids%2Cvideos%2Cwatch%2Fproviders')
        && str_contains($request->url(), 'include_video_language=en%2Cnull'));
});

test('show appends aggregate_credits, external_ids, videos, and watch/providers', function () {
    Http::fake([
        '*/tv/1396*' => Http::response(tmdbFixture('show_1396')),
    ]);

    $data = app(TmdbClient::class)->show(1396);

    expect($data['id'])->toBe(1396)
        ->and($data['aggregate_credits']['cast'])->toHaveCount(2);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'append_to_response=aggregate_credits%2Cexternal_ids%2Cvideos%2Cwatch%2Fproviders')
        && str_contains($request->url(), 'include_video_language=en%2Cnull'));
});

test('movieVideos hits the movie videos endpoint', function () {
    Http::fake([
        '*/movie/603/videos*' => Http::response(tmdbFixture('movie_603_with_trailer')['videos']),
    ]);

    $data = app(TmdbClient::class)->movieVideos(603);

    expect($data['results'])->toHaveCount(3);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/movie/603/videos')
        && str_contains($request->url(), 'include_video_language=en%2Cnull'));
});

test('showVideos hits the tv videos endpoint', function () {
    Http::fake([
        '*/tv/1396/videos*' => Http::response(tmdbFixture('show_1396_with_trailer')['videos']),
    ]);

    $data = app(TmdbClient::class)->showVideos(1396);

    expect($data['results'])->toHaveCount(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/tv/1396/videos')
        && str_contains($request->url(), 'include_video_language=en%2Cnull'));
});

test('seasonVideos hits the season videos endpoint', function () {
    Http::fake([
        '*/tv/1396/season/1/videos*' => Http::response(tmdbFixture('show_1396_with_trailer')['videos']),
    ]);

    $data = app(TmdbClient::class)->seasonVideos(1396, 1);

    expect($data['results'])->toHaveCount(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/tv/1396/season/1/videos')
        && str_contains($request->url(), 'include_video_language=en%2Cnull'));
});

test('includes the configured app locale alongside english in include_video_language', function () {
    config(['app.locale' => 'fr']);

    Http::fake([
        '*/movie/603*' => Http::response(tmdbFixture('movie_603')),
    ]);

    app(TmdbClient::class)->movie(603);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'include_video_language=fr%2Cen%2Cnull'));
});

test('episode appends credits and includes guest stars', function () {
    Http::fake([
        '*/tv/1396/season/1/episode/1*' => Http::response(tmdbFixture('tv_1396_season_1_episode_1')),
    ]);

    $data = app(TmdbClient::class)->episode(1396, 1, 1);

    expect($data['name'])->toBe('Pilot')
        ->and($data['credits']['cast'])->toHaveCount(2)
        ->and($data['credits']['guest_stars'])->toHaveCount(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'append_to_response=credits'));
});

test('seasons batches requests in groups of twenty', function () {
    $seasonNumbers = range(0, 24);

    Http::fake([
        '*/tv/1396*' => Http::response(tmdbFixture('show_1396_seasons')),
    ]);

    $seasons = app(TmdbClient::class)->seasons(1396, $seasonNumbers);

    expect($seasons)->toHaveKeys([0, 1, 2])
        ->and($seasons[1]['episodes'])->toHaveCount(2);

    Http::assertSentCount(2);

    Http::assertSent(function ($request) {
        $appendToResponse = $request->data()['append_to_response'] ?? '';

        return substr_count($appendToResponse, 'season/') === 20;
    });

    Http::assertSent(function ($request) {
        $appendToResponse = $request->data()['append_to_response'] ?? '';

        return substr_count($appendToResponse, 'season/') === 5;
    });
});

test('trending hits the trending endpoint for the given type and window', function () {
    Http::fake([
        '*/trending/all/week*' => Http::response(tmdbFixture('trending_all_week')),
    ]);

    $data = app(TmdbClient::class)->trending();

    expect($data['results'])->toHaveCount(3);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.themoviedb.org/3/trending/all/week');
});

test('nowPlayingMovies sends the region as a query parameter', function () {
    Http::fake([
        '*/movie/now_playing*' => Http::response(tmdbFixture('movie_now_playing')),
    ]);

    $data = app(TmdbClient::class)->nowPlayingMovies('US');

    expect($data['results'])->toHaveCount(2);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.themoviedb.org/3/movie/now_playing?region=US');
});

test('nowPlayingMovies omits the region parameter when null', function () {
    Http::fake([
        '*/movie/now_playing*' => Http::response(tmdbFixture('movie_now_playing')),
    ]);

    app(TmdbClient::class)->nowPlayingMovies();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.themoviedb.org/3/movie/now_playing');
});

test('upcomingMovies sends the region as a query parameter', function () {
    Http::fake([
        '*/movie/upcoming*' => Http::response(tmdbFixture('movie_upcoming')),
    ]);

    $data = app(TmdbClient::class)->upcomingMovies('US');

    expect($data['results'])->toHaveCount(2);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.themoviedb.org/3/movie/upcoming?region=US');
});

test('onTheAirShows hits the tv on_the_air endpoint', function () {
    Http::fake([
        '*/tv/on_the_air*' => Http::response(tmdbFixture('tv_on_the_air')),
    ]);

    $data = app(TmdbClient::class)->onTheAirShows();

    expect($data['results'])->toHaveCount(2);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.themoviedb.org/3/tv/on_the_air');
});

test('recommendations hits the movie recommendations endpoint for a movie', function () {
    Http::fake([
        '*/movie/603/recommendations*' => Http::response(tmdbFixture('movie_603_recommendations')),
    ]);

    $data = app(TmdbClient::class)->recommendations(TitleType::Movie, 603);

    expect($data['results'])->toHaveCount(2);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.themoviedb.org/3/movie/603/recommendations');
});

test('recommendations hits the tv recommendations endpoint for a show', function () {
    Http::fake([
        '*/tv/1396/recommendations*' => Http::response(tmdbFixture('tv_1396_recommendations')),
    ]);

    $data = app(TmdbClient::class)->recommendations(TitleType::Show, 1396);

    expect($data['results'])->toHaveCount(1);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.themoviedb.org/3/tv/1396/recommendations');
});

test('it retries on server errors and eventually succeeds', function () {
    Http::fake([
        '*/movie/603*' => Http::sequence()
            ->push(null, 500)
            ->push(null, 429)
            ->push(tmdbFixture('movie_603')),
    ]);

    $data = app(TmdbClient::class)->movie(603);

    expect($data['id'])->toBe(603);

    Http::assertSentCount(3);
});

test('it throws a TmdbException when retries are exhausted', function () {
    Http::fake([
        '*/movie/603*' => Http::response(null, 500),
    ]);

    app(TmdbClient::class)->movie(603);
})->throws(TmdbException::class);

test('it throws a TmdbException on a client error without retrying', function () {
    Http::fake([
        '*/movie/603*' => Http::response(['status_message' => 'not found'], 404),
    ]);

    try {
        app(TmdbClient::class)->movie(603);
    } catch (TmdbException $exception) {
        expect($exception->getMessage())->toContain('404');
    }

    Http::assertSentCount(1);
});

test('the database token takes priority over the env token', function () {
    app(IntegrationSettings::class)->set('tmdb.token', 'db-token');

    expect(app(TmdbClient::class)->token())->toBe('db-token');

    Http::fake([
        '*/movie/603*' => Http::response(['id' => 603]),
    ]);

    app(TmdbClient::class)->movie(603);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer db-token'));
});

test('it falls back to the env token when none is configured in the database', function () {
    expect(app(TmdbClient::class)->token())->toBe('test-token');
});

test('the database region takes priority over the env region', function () {
    config(['services.tmdb.region' => 'GB']);

    expect(app(TmdbClient::class)->region())->toBe('GB');

    app(IntegrationSettings::class)->set('tmdb.region', 'FR');

    expect(app(TmdbClient::class)->region())->toBe('FR');
});

test('testConnection returns true on a successful response', function () {
    Http::fake([
        '*/authentication*' => Http::response(['success' => true]),
    ]);

    expect(app(TmdbClient::class)->testConnection())->toBeTrue();
});

test('testConnection returns false when the token is rejected', function () {
    Http::fake([
        '*/authentication*' => Http::response(['status_message' => 'Invalid API key'], 401),
    ]);

    expect(app(TmdbClient::class)->testConnection())->toBeFalse();
});
