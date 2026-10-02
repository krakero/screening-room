<?php

use App\Actions\Plays\LogPlay;
use App\Enums\PlaySource;
use App\Enums\WatchedAt;
use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use Carbon\CarbonImmutable;

test('logging now sets watched_at to the current time', function () {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');

    $title = Title::factory()->movie()->create();

    $play = app(LogPlay::class)->handle($title, WatchedAt::Now);

    expect($play->watched_at->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($play->source)->toBe(PlaySource::Manual);

    CarbonImmutable::setTestNow();
});

test('logging on release date uses the movie release date', function () {
    $title = Title::factory()->movie()->create(['release_date' => '2020-05-01']);

    $play = app(LogPlay::class)->handle($title, WatchedAt::ReleaseDate);

    expect($play->watched_at->toDateString())->toBe('2020-05-01');
});

test('logging on release date uses the episode air date', function () {
    $season = Season::factory()->create();
    $episode = Episode::factory()->for($season)->create(['title_id' => $season->title_id, 'air_date' => '2021-09-15']);

    $play = app(LogPlay::class)->handle($episode, WatchedAt::ReleaseDate);

    expect($play->watched_at->toDateString())->toBe('2021-09-15');
});

test('logging on release date falls back to now when the release date is missing', function () {
    CarbonImmutable::setTestNow('2026-03-01 12:00:00');

    $title = Title::factory()->movie()->create(['release_date' => null]);

    $play = app(LogPlay::class)->handle($title, WatchedAt::ReleaseDate);

    expect($play->watched_at->equalTo(CarbonImmutable::now()))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('logging as unknown stores a null watched_at', function () {
    $title = Title::factory()->movie()->create();

    $play = app(LogPlay::class)->handle($title, WatchedAt::Unknown);

    expect($play->watched_at)->toBeNull();
});

test('logging a custom datetime stores that exact time', function () {
    $title = Title::factory()->movie()->create();

    $play = app(LogPlay::class)->handle($title, WatchedAt::Custom, CarbonImmutable::parse('2019-11-11 08:30'));

    expect($play->watched_at->format('Y-m-d H:i'))->toBe('2019-11-11 08:30');
});

test('logging a custom watched_at without a datetime throws', function () {
    $title = Title::factory()->movie()->create();

    app(LogPlay::class)->handle($title, WatchedAt::Custom);
})->throws(InvalidArgumentException::class);
