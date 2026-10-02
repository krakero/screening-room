<?php

use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Carbon;

test('isSpecial is true only for season 0', function () {
    $special = Episode::factory()->special()->create();
    $regular = Episode::factory()->create(['season_number' => 1]);

    expect($special->isSpecial())->toBeTrue()
        ->and($regular->isSpecial())->toBeFalse();
});

test('hasAired reflects whether the air date has passed', function () {
    $aired = Episode::factory()->aired()->create();
    $unaired = Episode::factory()->unaired()->create();

    expect($aired->hasAired())->toBeTrue()
        ->and($unaired->hasAired())->toBeFalse();
});

test('hasAired is false when there is no air date', function () {
    $episode = Episode::factory()->create(['air_date' => null]);

    expect($episode->hasAired())->toBeFalse();
});

test('hasAired uses the viewer\'s local date, not the UTC server date', function () {
    // Regression: 2026-09-30 03:18 UTC is already "tomorrow" in UTC, but it's still the evening
    // of 2026-09-29 in America/Chicago (UTC-5). An episode airing 2026-09-30 must not count as
    // aired yet for that viewer.
    $this->actingAs(User::factory()->create(['timezone' => 'America/Chicago']));

    Carbon::setTestNow('2026-09-30 03:18:00');
    $episode = Episode::factory()->create(['air_date' => '2026-09-30']);

    expect($episode->hasAired())->toBeFalse();

    Carbon::setTestNow('2026-09-30 20:00:00');

    expect($episode->hasAired())->toBeTrue();

    Carbon::setTestNow();
});

test('title and season relations resolve', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create();
    $episode = Episode::factory()->for($season)->create(['title_id' => $title->id]);

    expect($episode->title->is($title))->toBeTrue()
        ->and($episode->season->is($season))->toBeTrue();
});

test('stillUrl builds the tmdb cdn url or null', function () {
    config(['services.tmdb.image_base_url' => 'https://image.tmdb.org/t/p']);

    $episode = Episode::factory()->create(['still_path' => '/still.jpg']);
    $withoutStill = Episode::factory()->create(['still_path' => null]);

    expect($episode->stillUrl())->toBe('https://image.tmdb.org/t/p/w300/still.jpg')
        ->and($withoutStill->stillUrl())->toBeNull();
});
