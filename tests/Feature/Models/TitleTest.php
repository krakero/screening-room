<?php

use App\Enums\TitleType;
use App\Models\Episode;
use App\Models\MediaList;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Title;
use Carbon\CarbonImmutable;

test('casts attributes correctly', function () {
    $title = Title::factory()->create([
        'genres' => ['Drama', 'Comedy'],
        'in_production' => true,
    ]);

    expect($title->type)->toBeInstanceOf(TitleType::class)
        ->and($title->genres)->toBe(['Drama', 'Comedy'])
        ->and($title->release_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($title->in_production)->toBeTrue()
        ->and($title->tmdb_synced_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('seasons are ordered by season number', function () {
    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 2]);
    Season::factory()->for($title)->create(['season_number' => 0]);
    Season::factory()->for($title)->create(['season_number' => 1]);

    expect($title->seasons->pluck('season_number')->all())->toBe([0, 1, 2]);
});

test('episodes relation returns episodes for the title', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create();
    Episode::factory()->for($season)->count(3)->create(['title_id' => $title->id]);

    expect($title->episodes)->toHaveCount(3);
});

test('credits, plays, rating and media lists relations resolve', function () {
    $title = Title::factory()->create();
    Play::factory()->for($title, 'playable')->create();
    Rating::factory()->for($title, 'rateable')->create();
    $list = MediaList::factory()->create();
    $list->titles()->attach($title, ['position' => 0]);

    expect($title->plays)->toHaveCount(1)
        ->and($title->rating)->not->toBeNull()
        ->and($title->mediaLists)->toHaveCount(1);
});

test('posterUrl and backdropUrl build the tmdb cdn url', function () {
    config(['services.tmdb.image_base_url' => 'https://image.tmdb.org/t/p']);

    $title = Title::factory()->create([
        'poster_path' => '/poster.jpg',
        'backdrop_path' => '/backdrop.jpg',
    ]);

    expect($title->posterUrl())->toBe('https://image.tmdb.org/t/p/w342/poster.jpg')
        ->and($title->posterUrl('w500'))->toBe('https://image.tmdb.org/t/p/w500/poster.jpg')
        ->and($title->backdropUrl())->toBe('https://image.tmdb.org/t/p/w1280/backdrop.jpg');
});

test('posterUrl and backdropUrl return null when path is missing', function () {
    $title = Title::factory()->create(['poster_path' => null, 'backdrop_path' => null]);

    expect($title->posterUrl())->toBeNull()
        ->and($title->backdropUrl())->toBeNull();
});

test('isMovie and isShow reflect the type', function () {
    $movie = Title::factory()->movie()->create();
    $show = Title::factory()->show()->create();

    expect($movie->isMovie())->toBeTrue()
        ->and($movie->isShow())->toBeFalse()
        ->and($show->isShow())->toBeTrue()
        ->and($show->isMovie())->toBeFalse();
});

test('movies and shows scopes filter by type', function () {
    Title::factory()->movie()->count(2)->create();
    Title::factory()->show()->count(3)->create();

    expect(Title::movies()->count())->toBe(2)
        ->and(Title::shows()->count())->toBe(3);
});

test('yearRange returns the release year for a movie', function () {
    $title = Title::factory()->movie()->create(['release_date' => '2026-03-05']);

    expect($title->yearRange())->toBe('2026');
});

test('yearRange returns null for a movie without a release date', function () {
    $title = Title::factory()->movie()->create(['release_date' => null]);

    expect($title->yearRange())->toBeNull();
});

test('yearRange returns null for a show without a release date', function () {
    $title = Title::factory()->show()->create(['release_date' => null]);

    expect($title->yearRange())->toBeNull();
});

test('yearRange returns a single year when a show\'s start and end years match', function () {
    $title = Title::factory()->show()->create([
        'release_date' => '2026-01-10',
        'last_air_date' => '2026-11-20',
        'status' => 'Ended',
        'in_production' => false,
    ]);

    expect($title->yearRange())->toBe('2026');
});

test('yearRange returns a closed range for an ended show with different years', function () {
    $title = Title::factory()->show()->create([
        'release_date' => '2019-01-10',
        'last_air_date' => '2023-11-20',
        'status' => 'Ended',
        'in_production' => false,
    ]);

    expect($title->yearRange())->toBe('2019–2023');
});

test('yearRange returns a closed range for a canceled show with different years', function () {
    $title = Title::factory()->show()->create([
        'release_date' => '2019-01-10',
        'last_air_date' => '2020-11-20',
        'status' => 'Canceled',
        'in_production' => false,
    ]);

    expect($title->yearRange())->toBe('2019–2020');
});

test('yearRange returns an open range for a returning show with different years', function () {
    $title = Title::factory()->show()->create([
        'release_date' => '2019-01-10',
        'last_air_date' => '2023-11-20',
        'status' => 'Returning Series',
        'in_production' => false,
    ]);

    expect($title->yearRange())->toBe('2019+');
});

test('yearRange returns an open range for a show still in production with different years', function () {
    $title = Title::factory()->show()->create([
        'release_date' => '2019-01-10',
        'last_air_date' => '2023-11-20',
        'status' => 'In Production',
        'in_production' => true,
    ]);

    expect($title->yearRange())->toBe('2019+');
});

test('yearRange marks an ongoing show with no end date yet as open', function () {
    $title = Title::factory()->show()->create([
        'release_date' => '2026-01-10',
        'last_air_date' => null,
        'status' => 'Returning Series',
        'in_production' => true,
    ]);

    expect($title->yearRange())->toBe('2026+');
});

test('yearRange marks an ongoing show that has only aired in one year as open', function () {
    $title = Title::factory()->show()->create([
        'release_date' => '2026-01-10',
        'last_air_date' => '2026-11-20',
        'status' => 'Returning Series',
        'in_production' => false,
    ]);

    expect($title->yearRange())->toBe('2026+');
});
