<?php

use App\Enums\PlaySource;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Title;
use Carbon\CarbonImmutable;

test('casts source to enum and watched_at to datetime', function () {
    $play = Play::factory()->create(['source' => PlaySource::Plex]);

    expect($play->source)->toBe(PlaySource::Plex)
        ->and($play->watched_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('playable morphs to a title', function () {
    $title = Title::factory()->create();
    $play = Play::factory()->for($title, 'playable')->create();

    expect($play->playable)->toBeInstanceOf(Title::class)
        ->and($play->playable->is($title))->toBeTrue();
});

test('playable morphs to an episode', function () {
    $episode = Episode::factory()->create();
    $play = Play::factory()->for($episode, 'playable')->create();

    expect($play->playable)->toBeInstanceOf(Episode::class)
        ->and($play->playable->is($episode))->toBeTrue();
});
