<?php

use App\Actions\Tmdb\ImportMovie;
use App\Enums\CreditType;
use App\Enums\TitleType;
use App\Models\Title;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

function movieFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/movie_603.json')),
        true,
    );
}

test('it imports a movie with its credits', function () {
    Http::fake([
        '*/movie/603*' => Http::response(movieFixture()),
    ]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title)->toBeInstanceOf(Title::class)
        ->and($title->type)->toBe(TitleType::Movie)
        ->and($title->tmdb_id)->toBe(603)
        ->and($title->imdb_id)->toBe('tt0133093')
        ->and($title->name)->toBe('The Matrix')
        ->and($title->overview)->not->toBeNull()
        ->and($title->release_date->toDateString())->toBe('1999-03-30')
        ->and($title->runtime)->toBe(136)
        ->and($title->genres)->toBe(['Action', 'Science Fiction'])
        ->and($title->in_production)->toBeFalse()
        ->and($title->tmdb_synced_at)->not->toBeNull();

    expect($title->credits()->count())->toBe(4);

    $cast = $title->credits()->with('person')->where('type', CreditType::Cast)->orderBy('order')->get();
    expect($cast)->toHaveCount(2)
        ->and($cast->first()->character)->toBe('Neo')
        ->and($cast->first()->person->name)->toBe('Keanu Reeves');

    $crew = $title->credits()->where('type', CreditType::Crew)->get();
    expect($crew)->toHaveCount(2)
        ->and($crew->pluck('job')->all())->toBe(['Director', 'Writer']);
});

test('it stores the best trailer when videos are present', function () {
    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/movie_603_with_trailer.json')),
        true,
    );

    Http::fake([
        '*/movie/603*' => Http::response($fixture),
    ]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->trailer_site)->toBe('YouTube')
        ->and($title->trailer_key)->toBe('m8e-FF8MsqU');
});

test('it stores no trailer when there are no videos', function () {
    Http::fake([
        '*/movie/603*' => Http::response(movieFixture()),
    ]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->trailer_site)->toBeNull()
        ->and($title->trailer_key)->toBeNull();
});

test('it sets trailer_checked_at on import', function () {
    Http::fake([
        '*/movie/603*' => Http::response(movieFixture()),
    ]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->trailer_checked_at)->not->toBeNull();
});

test('it normalizes a blank release_date to null', function () {
    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/movie_603_blank_dates.json')),
        true,
    );

    Http::fake([
        '*/movie/603*' => Http::response($fixture),
    ]);

    $title = app(ImportMovie::class)->handle(603);

    expect($title->release_date)->toBeNull();
});

test('re-importing a movie is idempotent and replaces credits', function () {
    Http::fake([
        '*/movie/603*' => Http::response(movieFixture()),
    ]);

    $first = app(ImportMovie::class)->handle(603);
    $second = app(ImportMovie::class)->handle(603);

    expect($second->id)->toBe($first->id)
        ->and(Title::count())->toBe(1)
        ->and($second->credits()->count())->toBe(4);
});
