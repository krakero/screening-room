<?php

use App\Enums\FollowState;
use App\Enums\PlaySource;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;

test('a play on an episode auto-follows the show', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id]);

    Play::factory()->for($episode, 'playable')->create(['watched_at' => now(), 'source' => PlaySource::Manual]);

    $follow = Follow::query()->where('title_id', $title->id)->sole();
    expect($follow->state)->toBe(FollowState::Watching)
        ->and($follow->last_played_at)->not->toBeNull();
});

test('a play does not follow a movie', function () {
    $title = Title::factory()->create();

    Play::factory()->for($title, 'playable')->create();

    expect(Follow::query()->count())->toBe(0);
});

test('a play on a paused show resumes it and updates last_played_at', function () {
    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->paused()->create(['last_played_at' => now()->subYear()]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id]);

    $watchedAt = now()->startOfSecond();
    Play::factory()->for($episode, 'playable')->create(['watched_at' => $watchedAt, 'source' => PlaySource::Manual]);

    $follow->refresh();
    expect($follow->state)->toBe(FollowState::Watching)
        ->and($follow->last_played_at->equalTo($watchedAt))->toBeTrue();
});

test('a play on an abandoned show resumes it to watching', function () {
    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->abandoned()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id]);

    Play::factory()->for($episode, 'playable')->create(['watched_at' => now(), 'source' => PlaySource::Manual]);

    expect($follow->fresh()->state)->toBe(FollowState::Watching);
});

test('a play with an unknown watched_at still follows the show without updating last_played_at', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id]);

    Play::factory()->for($episode, 'playable')->create(['watched_at' => null, 'source' => PlaySource::Manual]);

    $follow = Follow::query()->where('title_id', $title->id)->sole();
    expect($follow->state)->toBe(FollowState::Watching)
        ->and($follow->last_played_at)->toBeNull();
});

test('a play with an unknown watched_at does not clear an existing last_played_at', function () {
    $title = Title::factory()->show()->create();
    $known = now()->subDay()->startOfSecond();
    $follow = Follow::factory()->for($title)->create(['last_played_at' => $known]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id]);

    Play::factory()->for($episode, 'playable')->create(['watched_at' => null, 'source' => PlaySource::Manual]);

    expect($follow->fresh()->last_played_at->equalTo($known))->toBeTrue();
});

test('the last play on an ended show marks it completed', function () {
    $title = Title::factory()->show()->create(['in_production' => false]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id]);

    Play::factory()->for($episode, 'playable')->create(['watched_at' => now(), 'source' => PlaySource::Manual]);

    $follow = Follow::query()->where('title_id', $title->id)->sole();
    expect($follow->state)->toBe(FollowState::Completed);
});
