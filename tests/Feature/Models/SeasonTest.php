<?php

use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;

test('title relation resolves', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create();

    expect($season->title->is($title))->toBeTrue();
});

test('episodes are ordered by episode number', function () {
    $season = Season::factory()->create();
    Episode::factory()->for($season)->create(['episode_number' => 3, 'title_id' => $season->title_id]);
    Episode::factory()->for($season)->create(['episode_number' => 1, 'title_id' => $season->title_id]);
    Episode::factory()->for($season)->create(['episode_number' => 2, 'title_id' => $season->title_id]);

    expect($season->episodes->pluck('episode_number')->all())->toBe([1, 2, 3]);
});

test('posterUrl builds the tmdb cdn url or null', function () {
    config(['services.tmdb.image_base_url' => 'https://image.tmdb.org/t/p']);

    $season = Season::factory()->create(['poster_path' => '/season.jpg']);
    $withoutPoster = Season::factory()->create(['poster_path' => null]);

    expect($season->posterUrl())->toBe('https://image.tmdb.org/t/p/w342/season.jpg')
        ->and($withoutPoster->posterUrl())->toBeNull();
});
