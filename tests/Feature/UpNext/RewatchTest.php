<?php

use App\Actions\Follows\PauseShow;
use App\Actions\Follows\RestartShow;
use App\Actions\Follows\ResumeShow;
use App\Actions\Follows\StopRewatch;
use App\Actions\Follows\SyncFollowState;
use App\Actions\Plays\LogPlay;
use App\Enums\FollowState;
use App\Enums\PlaySource;
use App\Enums\WatchedAt;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Services\ShowProgress;
use App\Services\UpNext\ContinueWatchingQuery;
use App\Services\Watch\WatchCacheVersion;

/**
 * A finished, two-episode show with a follow already completed — the common "restart a show
 * I've already finished" starting point for these tests.
 *
 * @return array{title: Title, follow: Follow, episode1: Episode, episode2: Episode}
 */
function makeCompletedShow(): array
{
    $title = Title::factory()->show()->create(['in_production' => false]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode1 = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id, 'episode_number' => 1]);
    $episode2 = Episode::factory()->for($season)->aired()->create(['title_id' => $title->id, 'episode_number' => 2]);

    Play::withoutEvents(function () use ($episode1, $episode2): void {
        $episode1->plays()->create(['watched_at' => now()->subYear(), 'source' => PlaySource::Manual]);
        $episode2->plays()->create(['watched_at' => now()->subYear(), 'source' => PlaySource::Manual]);
    });

    $follow = Follow::factory()->for($title)->completed()->create();

    return compact('title', 'follow', 'episode1', 'episode2');
}

test('a follow is rewatching only once rewatch_started_at is set', function () {
    $follow = Follow::factory()->create();

    expect($follow->isRewatching())->toBeFalse()
        ->and($follow->progressSince())->toBeNull();

    $follow->update(['rewatch_started_at' => now()]);

    expect($follow->fresh()->isRewatching())->toBeTrue()
        ->and($follow->fresh()->progressSince())->not->toBeNull();
});

test('show progress while rewatching counts only plays since the restart', function () {
    ['title' => $title, 'episode1' => $episode1, 'episode2' => $episode2] = makeCompletedShow();

    $restartedAt = now();
    Follow::query()->where('title_id', $title->id)->update(['rewatch_started_at' => $restartedAt, 'state' => FollowState::Watching]);

    $progress = app(ShowProgress::class);

    // Both episodes have old plays, but none since the restart.
    expect($progress->watchedEpisodeCount($title))->toBe(0)
        ->and($progress->nextEpisode($title)?->is($episode1))->toBeTrue()
        ->and($progress->isComplete($title))->toBeFalse();

    Play::withoutEvents(fn () => $episode1->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]));

    expect($progress->watchedEpisodeCount($title))->toBe(1)
        ->and($progress->nextEpisode($title)?->is($episode2))->toBeTrue();
});

test('an unknown-date play logged during the rewatch counts toward progress', function () {
    ['title' => $title, 'episode1' => $episode1] = makeCompletedShow();

    $restartedAt = now()->subMinutes(5);
    Follow::query()->where('title_id', $title->id)->update(['rewatch_started_at' => $restartedAt, 'state' => FollowState::Watching]);

    // watched_at is null (unknown date), but the row was created now, after the restart.
    Play::withoutEvents(fn () => $episode1->plays()->create(['watched_at' => null, 'source' => PlaySource::Manual]));

    expect(app(ShowProgress::class)->watchedEpisodeCount($title))->toBe(1);
});

test('an unknown-date play logged before the rewatch does not count toward progress', function () {
    ['title' => $title, 'episode1' => $episode1] = makeCompletedShow();

    Play::withoutEvents(function () use ($episode1): void {
        $play = $episode1->plays()->create(['watched_at' => null, 'source' => PlaySource::Manual]);
        $play->forceFill(['created_at' => now()->subMinute()])->save();
    });

    Follow::query()->where('title_id', $title->id)->update(['rewatch_started_at' => now(), 'state' => FollowState::Watching]);

    expect(app(ShowProgress::class)->watchedEpisodeCount($title))->toBe(0);
});

test('restarting a show works from every follow state, creating the follow if missing', function (?FollowState $state) {
    $title = Title::factory()->show()->create();

    if ($state !== null) {
        Follow::factory()->for($title)->create(['state' => $state]);
    }

    $follow = app(RestartShow::class)->handle($title);

    expect($follow->state)->toBe(FollowState::Watching)
        ->and($follow->rewatch_started_at)->not->toBeNull()
        ->and($follow->fresh()->state)->toBe(FollowState::Watching);
})->with([null, FollowState::Watching, FollowState::Paused, FollowState::Abandoned, FollowState::Completed]);

test('restarting a partially watched show resets progress to S1E1', function () {
    ['title' => $title, 'episode1' => $episode1] = makeCompletedShow();

    app(RestartShow::class)->handle($title);

    expect(app(ShowProgress::class)->nextEpisode($title)?->is($episode1))->toBeTrue();
});

test('restarting preserves the existing rewatch count', function () {
    ['title' => $title, 'follow' => $follow] = makeCompletedShow();
    $follow->update(['rewatch_count' => 3]);

    $restarted = app(RestartShow::class)->handle($title);

    expect($restarted->rewatch_count)->toBe(3);
});

test('pausing and resuming a rewatch keeps its progress', function () {
    ['title' => $title, 'follow' => $follow] = makeCompletedShow();
    app(RestartShow::class)->handle($title);
    $follow = $follow->fresh();
    $since = $follow->rewatch_started_at;

    app(PauseShow::class)->handle($follow);
    expect($follow->fresh()->state)->toBe(FollowState::Paused)
        ->and($follow->fresh()->rewatch_started_at)->toEqual($since);

    app(ResumeShow::class)->handle($follow->fresh());
    expect($follow->fresh()->state)->toBe(FollowState::Watching)
        ->and($follow->fresh()->rewatch_started_at)->toEqual($since);
});

test('stopping a rewatch clears it and recomputes completed when everything has ever been watched', function () {
    ['title' => $title, 'follow' => $follow] = makeCompletedShow();
    app(RestartShow::class)->handle($title);

    app(StopRewatch::class)->handle($title);

    expect($follow->fresh()->rewatch_started_at)->toBeNull()
        ->and($follow->fresh()->state)->toBe(FollowState::Completed);
});

test('stopping a rewatch recomputes watching when not everything has ever been watched', function () {
    $title = Title::factory()->show()->create(['in_production' => false]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->for($season)->aired()->create(['title_id' => $title->id, 'episode_number' => 1]);
    Episode::factory()->for($season)->aired()->create(['title_id' => $title->id, 'episode_number' => 2]);
    $follow = Follow::factory()->for($title)->rewatching()->create();

    app(StopRewatch::class)->handle($title);

    expect($follow->fresh()->rewatch_started_at)->toBeNull()
        ->and($follow->fresh()->state)->toBe(FollowState::Watching);
});

test('stopping a rewatch on a show that was never rewatching does nothing', function () {
    $follow = Follow::factory()->create();

    $result = app(StopRewatch::class)->handle($follow->title);

    expect($result?->id)->toBe($follow->id)
        ->and($follow->fresh()->state)->toBe($follow->state);
});

test('finishing a rewatch clears it, bumps the count, and completes the follow', function () {
    ['title' => $title, 'episode1' => $episode1, 'episode2' => $episode2] = makeCompletedShow();
    app(RestartShow::class)->handle($title);
    $follow = $title->follow()->first();

    app(LogPlay::class)->handle($episode1, WatchedAt::Now);
    expect($follow->fresh()->isRewatching())->toBeTrue()
        ->and($follow->fresh()->state)->toBe(FollowState::Watching);

    app(LogPlay::class)->handle($episode2, WatchedAt::Now);

    expect($follow->fresh()->isRewatching())->toBeFalse()
        ->and($follow->fresh()->rewatch_count)->toBe(1)
        ->and($follow->fresh()->state)->toBe(FollowState::Completed);
});

test('sync leaves a paused rewatch untouched even once complete since the restart', function () {
    ['follow' => $follow, 'episode1' => $episode1, 'episode2' => $episode2] = makeCompletedShow();
    $follow->update(['rewatch_started_at' => now(), 'state' => FollowState::Paused]);

    Play::withoutEvents(function () use ($episode1, $episode2): void {
        $episode1->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]);
        $episode2->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]);
    });

    app(SyncFollowState::class)->handle($follow->fresh());

    expect($follow->fresh()->state)->toBe(FollowState::Paused)
        ->and($follow->fresh()->isRewatching())->toBeTrue();
});

test('continue watching shows a restarted show at S1E1', function () {
    ['title' => $title, 'episode1' => $episode1] = makeCompletedShow();

    app(RestartShow::class)->handle($title);

    $entries = app(ContinueWatchingQuery::class)->get();
    $entry = $entries->firstWhere(fn (array $entry): bool => $entry['title']->is($title));

    expect($entry)->not->toBeNull()
        ->and($entry['progress']->nextEpisode?->is($episode1))->toBeTrue();
});

test('restarting a show bumps the watch cache version', function () {
    $title = Title::factory()->show()->create();
    Follow::factory()->for($title)->completed()->create();
    $before = app(WatchCacheVersion::class)->current();

    app(RestartShow::class)->handle($title);

    expect(app(WatchCacheVersion::class)->current())->toBeGreaterThan($before);
});

test('stopping a rewatch bumps the watch cache version', function () {
    ['title' => $title] = makeCompletedShow();
    app(RestartShow::class)->handle($title);
    $before = app(WatchCacheVersion::class)->current();

    app(StopRewatch::class)->handle($title);

    expect(app(WatchCacheVersion::class)->current())->toBeGreaterThan($before);
});

test('finishing a rewatch bumps the watch cache version', function () {
    ['title' => $title, 'episode1' => $episode1, 'episode2' => $episode2] = makeCompletedShow();
    app(RestartShow::class)->handle($title);
    app(LogPlay::class)->handle($episode1, WatchedAt::Now);
    $before = app(WatchCacheVersion::class)->current();

    app(LogPlay::class)->handle($episode2, WatchedAt::Now);

    expect(app(WatchCacheVersion::class)->current())->toBeGreaterThan($before);
});

test('a play logged through the normal play-recording path counts toward an active rewatch', function () {
    ['title' => $title, 'episode1' => $episode1, 'episode2' => $episode2] = makeCompletedShow();
    app(RestartShow::class)->handle($title);
    $follow = $title->follow()->first();

    // Simulates a Plex scrobble / Trakt import play — both create Plays through the model
    // (firing PlayObserver), same as LogPlay, so SyncFollowState runs after them too.
    $episode1->plays()->create(['watched_at' => now(), 'source' => PlaySource::Plex, 'external_id' => 'plex-1']);
    $episode2->plays()->create(['watched_at' => now(), 'source' => PlaySource::Trakt, 'external_id' => 'trakt-1']);

    expect($follow->fresh()->isRewatching())->toBeFalse()
        ->and($follow->fresh()->rewatch_count)->toBe(1)
        ->and($follow->fresh()->state)->toBe(FollowState::Completed);
});
