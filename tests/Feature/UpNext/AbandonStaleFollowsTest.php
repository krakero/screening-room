<?php

use App\Enums\FollowState;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;

/**
 * Creates a show with one aired episode and, unless $watched, no play for it — i.e. a show
 * with an unwatched aired episode ("behind") vs. fully caught up.
 */
function showWithAiredEpisode(bool $watched, bool $inProduction = true): Title
{
    $title = Title::factory()->show()->create(['in_production' => $inProduction]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    if ($watched) {
        // PlayObserver auto-follows the show, so the follow may already exist by the time
        // the test sets it up further via followWithState().
        Play::factory()->for($episode, 'playable')->create();
    }

    return $title;
}

/**
 * Gets (or creates) the follow for a title and forces it into the given state/attributes,
 * regardless of whether PlayObserver already auto-created one.
 */
function followWithState(Title $title, array $attributes): Follow
{
    $follow = Follow::query()->where('title_id', $title->id)->first()
        ?? Follow::factory()->for($title)->create();

    $follow->update($attributes);

    return $follow->fresh();
}

test('abandons a stale watching follow with an unwatched aired episode', function () {
    config(['showing.abandon_after_days' => 180]);

    $title = showWithAiredEpisode(watched: false);
    $stale = followWithState($title, ['last_played_at' => now()->subDays(200)]);

    $this->artisan('follows:abandon-stale')->assertSuccessful();

    expect($stale->fresh()->state)->toBe(FollowState::Abandoned);
});

test('leaves a stale watching follow alone when it is caught up', function () {
    config(['showing.abandon_after_days' => 180]);

    $title = showWithAiredEpisode(watched: true);
    $caughtUp = followWithState($title, ['last_played_at' => now()->subDays(200)]);

    $this->artisan('follows:abandon-stale')->assertSuccessful();

    expect($caughtUp->fresh()->state)->toBe(FollowState::Watching);
});

test('leaves a recently played watching follow alone', function () {
    config(['showing.abandon_after_days' => 180]);

    $title = showWithAiredEpisode(watched: false);
    $recent = followWithState($title, ['last_played_at' => now()->subDays(10)]);

    $this->artisan('follows:abandon-stale')->assertSuccessful();

    expect($recent->fresh()->state)->toBe(FollowState::Watching);
});

test('abandons a never-played stale follow with an unwatched aired episode', function () {
    config(['showing.abandon_after_days' => 180]);

    $title = showWithAiredEpisode(watched: false);
    $neverPlayedStale = followWithState($title, ['last_played_at' => null, 'state_changed_at' => now()->subDays(200)]);

    $this->artisan('follows:abandon-stale')->assertSuccessful();

    expect($neverPlayedStale->fresh()->state)->toBe(FollowState::Abandoned);
});

test('leaves a never-played recent follow alone', function () {
    config(['showing.abandon_after_days' => 180]);

    $title = showWithAiredEpisode(watched: false);
    $neverPlayedRecent = followWithState($title, ['last_played_at' => null, 'state_changed_at' => now()->subDays(5)]);

    $this->artisan('follows:abandon-stale')->assertSuccessful();

    expect($neverPlayedRecent->fresh()->state)->toBe(FollowState::Watching);
});

test('revives an abandoned follow that is caught up', function () {
    $title = showWithAiredEpisode(watched: true);
    $abandoned = followWithState($title, ['state' => FollowState::Abandoned, 'state_changed_at' => now()]);

    $this->artisan('follows:abandon-stale')->assertSuccessful();

    expect($abandoned->fresh()->state)->toBe(FollowState::Watching);
});

test('completes a revived follow whose show has finished airing and is fully watched', function () {
    $title = showWithAiredEpisode(watched: true, inProduction: false);
    $abandoned = followWithState($title, ['state' => FollowState::Abandoned, 'state_changed_at' => now()]);

    $this->artisan('follows:abandon-stale')->assertSuccessful();

    expect($abandoned->fresh()->state)->toBe(FollowState::Completed);
});

test('leaves an abandoned follow alone when it still has an unwatched aired episode', function () {
    $title = showWithAiredEpisode(watched: false);
    $abandoned = followWithState($title, ['state' => FollowState::Abandoned, 'state_changed_at' => now()]);

    $this->artisan('follows:abandon-stale')->assertSuccessful();

    expect($abandoned->fresh()->state)->toBe(FollowState::Abandoned);
});

test('leaves paused follows untouched', function () {
    $title = showWithAiredEpisode(watched: false);
    $paused = followWithState($title, ['state' => FollowState::Paused, 'last_played_at' => now()->subDays(400)]);

    $this->artisan('follows:abandon-stale')->assertSuccessful();

    expect($paused->fresh()->state)->toBe(FollowState::Paused);
});

test('respects a configurable abandon window', function () {
    config(['showing.abandon_after_days' => 30]);

    $title = showWithAiredEpisode(watched: false);
    $follow = followWithState($title, ['last_played_at' => now()->subDays(40)]);

    $this->artisan('follows:abandon-stale');

    expect($follow->fresh()->state)->toBe(FollowState::Abandoned);
});
