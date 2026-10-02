<?php

use App\Actions\Plays\MarkSeasonWatched;
use App\Enums\WatchedAt;
use App\Jobs\ImportSeasonEpisodes;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('marking a season watched respects the chosen watched-at option', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'air_date' => '2020-02-02']);

    app(MarkSeasonWatched::class)->handle($season, WatchedAt::ReleaseDate);

    expect($episode->plays()->sole()->watched_at->toDateString())->toBe('2020-02-02');
});

test('marking a season watched as unknown stores null watched_at', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);

    app(MarkSeasonWatched::class)->handle($season, WatchedAt::Unknown);

    expect($episode->plays()->sole()->watched_at)->toBeNull();
});

test('marking a season watched with a custom datetime applies it to every logged play', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $a = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);
    $b = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);

    app(MarkSeasonWatched::class)->handle($season, WatchedAt::Custom, CarbonImmutable::parse('2018-06-06 10:00'));

    expect($a->plays()->sole()->watched_at->format('Y-m-d H:i'))->toBe('2018-06-06 10:00')
        ->and($b->plays()->sole()->watched_at->format('Y-m-d H:i'))->toBe('2018-06-06 10:00');
});

test('marking a season watched does not duplicate plays for already-watched episodes', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);
    Play::factory()->for($episode, 'playable')->create();

    app(MarkSeasonWatched::class)->handle($season, WatchedAt::Now);

    expect($episode->plays()->count())->toBe(1);
});

test('marking an unloaded season watched does not block on a synchronous TMDB sync', function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
    Http::preventStrayRequests();
    Http::fake();
    Queue::fake();

    $title = Title::factory()->show()->create(['tmdb_id' => 4001]);
    $season = Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);

    app(MarkSeasonWatched::class)->handle($season, WatchedAt::Now);

    Http::assertNothingSent();
});

test('marking a stale season watched queues an episode refresh instead of syncing inline', function () {
    Queue::fake();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);

    app(MarkSeasonWatched::class)->handle($season, WatchedAt::Now);

    Queue::assertPushed(ImportSeasonEpisodes::class, fn (ImportSeasonEpisodes $job) => $job->titleId === $title->id && $job->seasonNumber === 1);
});
