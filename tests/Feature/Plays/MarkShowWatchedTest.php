<?php

use App\Actions\Plays\MarkShowWatched;
use App\Enums\WatchedAt;
use App\Jobs\ImportSeasonEpisodes;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('marking a show watched logs plays for every aired, unwatched, non-special episode', function () {
    $title = Title::factory()->show()->create();
    $season1 = Season::factory()->for($title)->create(['season_number' => 1]);
    $season2 = Season::factory()->for($title)->create(['season_number' => 2]);
    $specials = Season::factory()->for($title)->create(['season_number' => 0]);

    $watched = Episode::factory()->aired()->for($season1)->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($watched, 'playable')->create();

    $unwatchedSeason1 = Episode::factory()->aired()->for($season1)->create(['title_id' => $title->id, 'season_number' => 1]);
    $unwatchedSeason2 = Episode::factory()->aired()->for($season2)->create(['title_id' => $title->id, 'season_number' => 2]);
    $unaired = Episode::factory()->unaired()->for($season2)->create(['title_id' => $title->id, 'season_number' => 2]);
    $special = Episode::factory()->aired()->for($specials)->create(['title_id' => $title->id, 'season_number' => 0]);

    app(MarkShowWatched::class)->handle($title, WatchedAt::Now);

    expect($watched->plays()->count())->toBe(1)
        ->and($unwatchedSeason1->plays()->count())->toBe(1)
        ->and($unwatchedSeason2->plays()->count())->toBe(1)
        ->and($unaired->plays()->count())->toBe(0)
        ->and($special->plays()->count())->toBe(0);
});

test('marking a show watched as unknown stores null watched_at for logged plays', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);

    app(MarkShowWatched::class)->handle($title, WatchedAt::Unknown);

    expect($episode->plays()->sole()->watched_at)->toBeNull();
});

test('marking a show watched with unloaded seasons does not block on a synchronous TMDB sync', function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();

    $title = Title::factory()->show()->create(['tmdb_id' => 4002]);
    Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);

    app(MarkShowWatched::class)->handle($title, WatchedAt::Now);

    Http::assertNothingSent();
});

test('marking a show watched queues an episode refresh for every stale season instead of syncing inline', function () {
    Queue::fake();

    $title = Title::factory()->show()->create();
    Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);
    Season::factory()->notLoaded()->for($title)->create(['season_number' => 2]);
    Season::factory()->for($title)->create(['season_number' => 3, 'episodes_synced_at' => now()]);

    app(MarkShowWatched::class)->handle($title, WatchedAt::Now);

    Queue::assertPushed(ImportSeasonEpisodes::class, 2);
    Queue::assertPushed(ImportSeasonEpisodes::class, fn (ImportSeasonEpisodes $job) => $job->titleId === $title->id && $job->seasonNumber === 1);
    Queue::assertPushed(ImportSeasonEpisodes::class, fn (ImportSeasonEpisodes $job) => $job->titleId === $title->id && $job->seasonNumber === 2);
});
