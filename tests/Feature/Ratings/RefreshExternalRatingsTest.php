<?php

use App\Actions\Ratings\RefreshExternalRatings;
use App\Enums\RatingSource;
use App\Jobs\RefreshTitleRatings;
use App\Models\ExternalRating;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    app(IntegrationSettings::class)->set('mdblist.api_key', 'test-key');
});

test('it stores only the sources we display, ignoring the rest', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt0133093']);

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt0133093/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/mdblist/movie_603.json')), true),
        ),
    ]);

    app(RefreshExternalRatings::class)->handle($title);

    $ratings = ExternalRating::where('title_id', $title->id)->get()->keyBy(fn (ExternalRating $rating) => $rating->source->value);

    expect($ratings)->toHaveCount(6)
        ->and($ratings['imdb']->value)->toBe(8.7)
        ->and($ratings['imdb']->max)->toBe(10.0)
        ->and($ratings['imdb']->votes)->toBe(2100000)
        ->and($ratings['tomatoes']->value)->toBe(83.0)
        ->and($ratings['tomatoes']->url)->toBe('https://www.rottentomatoes.com/m/matrix')
        ->and($ratings['tomatoesaudience']->value)->toBe(85.0)
        ->and($ratings['metacritic']->value)->toBe(73.0)
        ->and($ratings['letterboxd']->value)->toBe(4.1)
        ->and($ratings['letterboxd']->max)->toBe(5.0)
        ->and($ratings['trakt']->value)->toBe(87.0)
        ->and($ratings)->not->toHaveKey('metacriticuser')
        ->and($ratings)->not->toHaveKey('tmdb');

    expect($title->refresh()->ratings_checked_at)->not->toBeNull();
});

test('it looks up by tmdb id when the title has no imdb id', function () {
    $title = Title::factory()->show()->create(['imdb_id' => null, 'tmdb_id' => 1396]);

    Http::fake([
        'https://api.mdblist.com/tmdb/show/1396/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/mdblist/show_1396.json')), true),
        ),
    ]);

    app(RefreshExternalRatings::class)->handle($title);

    expect(ExternalRating::where('title_id', $title->id)->count())->toBe(5);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.mdblist.com/tmdb/show/1396/?apikey=test-key');
});

test('it upserts on refresh instead of duplicating rows', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt0133093']);

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt0133093/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/mdblist/movie_603.json')), true),
        ),
    ]);

    app(RefreshExternalRatings::class)->handle($title);
    $firstFetchedAt = ExternalRating::where('title_id', $title->id)->where('source', RatingSource::Imdb)->sole()->fetched_at;

    Carbon::setTestNow(Carbon::now()->addHour());
    app(RefreshExternalRatings::class)->handle($title);
    Carbon::setTestNow();

    $imdbRatings = ExternalRating::where('title_id', $title->id)->where('source', RatingSource::Imdb)->get();

    expect($imdbRatings)->toHaveCount(1)
        ->and($imdbRatings->sole()->fetched_at->isAfter($firstFetchedAt))->toBeTrue();

    expect(ExternalRating::where('title_id', $title->id)->count())->toBe(6);
});

test('it records the checked timestamp even when mdblist has no ratings for the title', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt9999999']);

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt9999999/*' => Http::response(['id' => 0, 'title' => 'Unknown', 'ratings' => []]),
    ]);

    app(RefreshExternalRatings::class)->handle($title);

    expect(ExternalRating::where('title_id', $title->id)->count())->toBe(0)
        ->and($title->refresh()->ratings_checked_at)->not->toBeNull();
});

test('it does nothing when mdblist is not configured', function () {
    app(IntegrationSettings::class)->forget('mdblist.api_key');

    $title = Title::factory()->movie()->create(['imdb_id' => 'tt0133093']);

    app(RefreshExternalRatings::class)->handle($title);

    Http::assertNothingSent();
    expect(ExternalRating::where('title_id', $title->id)->count())->toBe(0)
        ->and($title->refresh()->ratings_checked_at)->toBeNull();
});

test('it does not advance the checked timestamp when mdblist returns an error', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt0133093']);

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt0133093/*' => Http::response(['error' => 'Not Found'], 404),
    ]);

    app(RefreshExternalRatings::class)->handle($title);

    expect(ExternalRating::where('title_id', $title->id)->count())->toBe(0)
        ->and($title->refresh()->ratings_checked_at)->toBeNull();
});

test('it does not advance the checked timestamp when mdblist rate limits the request', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt0133093']);

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt0133093/*' => Http::response(['error' => 'Too Many Requests'], 429),
    ]);

    app(RefreshExternalRatings::class)->handle($title);

    expect(ExternalRating::where('title_id', $title->id)->count())->toBe(0)
        ->and($title->refresh()->ratings_checked_at)->toBeNull();
});

test('the queued job releases itself for later on a 429 instead of completing', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt0133093']);

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt0133093/*' => Http::response(['error' => 'Too Many Requests'], 429),
    ]);

    $job = (new RefreshTitleRatings($title))->withFakeQueueInteractions();
    $job->handle(app(RefreshExternalRatings::class));

    $job->assertReleased(delay: RefreshTitleRatings::RATE_LIMIT_BACKOFF);
    expect($title->refresh()->ratings_checked_at)->toBeNull();
});
