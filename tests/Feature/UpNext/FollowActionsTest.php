<?php

use App\Actions\Follows\FollowShow;
use App\Actions\Follows\PauseShow;
use App\Actions\Follows\ResumeShow;
use App\Actions\Follows\SyncFollowState;
use App\Enums\FollowState;
use App\Enums\PlaySource;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;

test('following a show creates a watching follow', function () {
    $title = Title::factory()->show()->create();

    $follow = app(FollowShow::class)->handle($title);

    expect($follow)->toBeInstanceOf(Follow::class)
        ->and($follow->title_id)->toBe($title->id)
        ->and($follow->state)->toBe(FollowState::Watching);
});

test('following an already-watching show is idempotent', function () {
    $title = Title::factory()->show()->create();
    $existing = Follow::factory()->for($title)->create();

    $follow = app(FollowShow::class)->handle($title);

    expect($follow->id)->toBe($existing->id)
        ->and(Follow::query()->count())->toBe(1);
});

test('following a paused or abandoned show moves it back to watching', function (FollowState $state) {
    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->create(['state' => $state]);

    app(FollowShow::class)->handle($title);

    expect($follow->fresh()->state)->toBe(FollowState::Watching);
})->with([FollowState::Paused, FollowState::Abandoned]);

test('pausing a show sets the paused state', function () {
    $follow = Follow::factory()->create();

    app(PauseShow::class)->handle($follow);

    expect($follow->fresh()->state)->toBe(FollowState::Paused);
});

test('resuming a show sets it back to watching', function () {
    $follow = Follow::factory()->abandoned()->create();

    app(ResumeShow::class)->handle($follow);

    expect($follow->fresh()->state)->toBe(FollowState::Watching);
});

test('sync marks a follow completed once the show has ended and everything is watched', function () {
    $title = Title::factory()->show()->create(['in_production' => false]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id]);
    Play::withoutEvents(fn () => $episode->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]));
    $follow = Follow::factory()->for($title)->create();

    app(SyncFollowState::class)->handle($follow);

    expect($follow->fresh()->state)->toBe(FollowState::Completed);
});

test('sync leaves a paused follow untouched even if the show is complete', function () {
    $title = Title::factory()->show()->create(['in_production' => false]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id]);
    Play::withoutEvents(fn () => $episode->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]));
    $follow = Follow::factory()->for($title)->paused()->create();

    app(SyncFollowState::class)->handle($follow);

    expect($follow->fresh()->state)->toBe(FollowState::Paused);
});

test('sync moves a completed follow back to watching once a new episode airs', function () {
    $title = Title::factory()->show()->create(['in_production' => true]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->for($season)->unaired()->create(['title_id' => $title->id]);
    $follow = Follow::factory()->for($title)->completed()->create();

    app(SyncFollowState::class)->handle($follow);

    expect($follow->fresh()->state)->toBe(FollowState::Watching);
});
