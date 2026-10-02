<?php

use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\UpNext\ContinueWatchingQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('a show is shown when its next episode is in the current season', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Follow::factory()->for($title)->create();

    $titleIds = app(ContinueWatchingQuery::class)->get()->pluck('title.id');

    expect($titleIds)->toContain($title->id);
});

test('a show is shown when its next episode is in an older season but the previous episode was watched recently', function () {
    $title = Title::factory()->show()->create();
    $seasonOne = Season::factory()->for($title)->create(['season_number' => 1]);
    $seasonTwo = Season::factory()->for($title)->create(['season_number' => 2]);
    $previousEpisode = Episode::factory()->aired()->for($seasonOne)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->aired()->for($seasonOne)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 2]);
    Episode::factory()->aired()->for($seasonTwo)->create(['title_id' => $title->id, 'season_number' => 2, 'episode_number' => 1]);
    Play::factory()->for($previousEpisode, 'playable')->create(['watched_at' => now()->subDays(10)]);

    $titleIds = app(ContinueWatchingQuery::class)->get()->pluck('title.id');

    expect($titleIds)->toContain($title->id);
});

test('a show is hidden when its next episode is in an older season and the previous episode was watched more than 30 days ago', function () {
    $title = Title::factory()->show()->create();
    $seasonOne = Season::factory()->for($title)->create(['season_number' => 1]);
    $seasonTwo = Season::factory()->for($title)->create(['season_number' => 2]);
    $previousEpisode = Episode::factory()->aired()->for($seasonOne)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->aired()->for($seasonOne)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 2]);
    Episode::factory()->aired()->for($seasonTwo)->create(['title_id' => $title->id, 'season_number' => 2, 'episode_number' => 1]);
    Play::factory()->for($previousEpisode, 'playable')->create(['watched_at' => now()->subDays(45)]);

    $titleIds = app(ContinueWatchingQuery::class)->get()->pluck('title.id');

    expect($titleIds)->not->toContain($title->id);
});

test('a show is hidden when its next episode is in an older season with no tracked previous episode (started mid-way)', function () {
    $title = Title::factory()->show()->create();
    $seasonOne = Season::factory()->for($title)->create(['season_number' => 1]);
    $seasonTwo = Season::factory()->for($title)->create(['season_number' => 2]);
    Episode::factory()->aired()->for($seasonOne)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->aired()->for($seasonTwo)->create(['title_id' => $title->id, 'season_number' => 2, 'episode_number' => 1]);
    Follow::factory()->for($title)->create();

    $titleIds = app(ContinueWatchingQuery::class)->get()->pluck('title.id');

    expect($titleIds)->not->toContain($title->id);
});

test('specials and unaired seasons do not count as the current season', function () {
    $title = Title::factory()->show()->create();
    $special = Season::factory()->for($title)->create(['season_number' => 0]);
    $seasonOne = Season::factory()->for($title)->create(['season_number' => 1]);
    $seasonTwo = Season::factory()->for($title)->create(['season_number' => 2]);
    Episode::factory()->aired()->for($special)->create(['title_id' => $title->id, 'season_number' => 0, 'episode_number' => 1]);
    $lastOfSeasonOne = Episode::factory()->aired()->for($seasonOne)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->unaired()->for($seasonTwo)->create(['title_id' => $title->id, 'season_number' => 2, 'episode_number' => 1]);
    Follow::factory()->for($title)->create();

    $titleIds = app(ContinueWatchingQuery::class)->get()->pluck('title.id');

    expect($titleIds)->toContain($title->id);

    $entry = app(ContinueWatchingQuery::class)->get()->firstWhere('title.id', $title->id);
    expect($entry['progress']->nextEpisode->is($lastOfSeasonOne))->toBeTrue();
});

test('an episode airing tomorrow in UTC but still today locally is not yet the next episode', function () {
    // Regression: 2026-09-30 03:18 UTC is already "tomorrow" in UTC, but still the evening of
    // 2026-09-29 in America/Chicago (UTC-5). The episode's air_date of 2026-09-30 must not count
    // as aired for that viewer yet.
    $this->actingAs(User::factory()->create(['timezone' => 'America/Chicago']));
    Carbon::setTestNow('2026-09-30 03:18:00');

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1, 'air_date' => '2026-09-30']);
    Follow::factory()->for($title)->create();

    $titleIds = app(ContinueWatchingQuery::class)->get()->pluck('title.id');

    expect($titleIds)->not->toContain($title->id);

    Carbon::setTestNow('2026-09-30 20:00:00');

    $titleIds = app(ContinueWatchingQuery::class)->get()->pluck('title.id');

    expect($titleIds)->toContain($title->id);

    Carbon::setTestNow();
});

test('the query count stays flat no matter how many episodes a show has', function () {
    $title = Title::factory()->show()->create();
    $seasonOne = Season::factory()->for($title)->create(['season_number' => 1]);
    $seasonTwo = Season::factory()->for($title)->create(['season_number' => 2]);
    Episode::factory()->aired()->count(200)->for($seasonOne)->create(['title_id' => $title->id, 'season_number' => 1]);
    Episode::factory()->aired()->for($seasonTwo)->create(['title_id' => $title->id, 'season_number' => 2, 'episode_number' => 1]);
    Follow::factory()->for($title)->create();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    app(ContinueWatchingQuery::class)->get();

    expect($queryCount)->toBeLessThan(10);
});
