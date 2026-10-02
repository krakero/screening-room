<?php

use App\Actions\Follows\PauseShow;
use App\Actions\Plays\LogPlay;
use App\Enums\TitleType;
use App\Jobs\ImportSeasonEpisodes;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\MediaList;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\Tmdb\TmdbClient;
use App\Services\UpNext\UpNextCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function seasonOneFixtureForWatchCache(): array
{
    return [
        'id' => 1396,
        'season/1' => json_decode(
            file_get_contents(base_path('tests/Fixtures/tmdb/show_1396_seasons.json')),
            true,
        )['season/1'],
    ];
}

test('a cache miss computes and caches; a cache hit skips the query service and only pays for cheap hydration', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $show->id, 'season_number' => 1]);
    Follow::factory()->for($show)->create();

    $first = app(UpNextCache::class)->continueWatching();
    expect($first)->toHaveCount(1)->and($first->first()['title']->id)->toBe($show->id);

    DB::enableQueryLog();
    $second = app(UpNextCache::class)->continueWatching();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // A hit still pays for hydrating the cached ids (a couple of cheap indexed whereIns) but
    // never re-runs ContinueWatchingQuery's heavier joins/window-function queries.
    expect($second)->toHaveCount(1);
    expect($queryCount)->toBeLessThanOrEqual(2);
});

test('logging a play invalidates the cache', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $first = Episode::factory()->aired()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'episode_number' => 1]);
    $second = Episode::factory()->aired()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'episode_number' => 2]);
    Follow::factory()->for($show)->create();

    expect(app(UpNextCache::class)->continueWatching()->first()['progress']->nextEpisode->id)->toBe($first->id);

    app(LogPlay::class)->handle($first);

    expect(app(UpNextCache::class)->continueWatching()->first()['progress']->nextEpisode->id)->toBe($second->id);
});

test('removing a play invalidates the cache', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'episode_number' => 1]);
    Follow::factory()->for($show)->create();

    app(LogPlay::class)->handle($episode);
    expect(app(UpNextCache::class)->continueWatching())->toBeEmpty();

    $episode->plays()->first()->delete();

    expect(app(UpNextCache::class)->continueWatching()->first()['progress']->nextEpisode->id)->toBe($episode->id);
});

test('pausing a follow invalidates the cache', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $show->id, 'season_number' => 1]);
    $follow = Follow::factory()->for($show)->create();

    expect(app(UpNextCache::class)->continueWatching())->toHaveCount(1);

    app(PauseShow::class)->handle($follow);

    expect(app(UpNextCache::class)->continueWatching())->toBeEmpty();
});

test('adding to and removing from the watchlist refreshes the Recently Added to Watchlist section', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));
    $watchlist = MediaList::watchlist();

    expect(app(UpNextCache::class)->recentlyWatchlisted())->toBeEmpty();

    $title = Title::factory()->movie()->create(['name' => 'Freshly Added']);

    expect(app(UpNextCache::class)->recentlyWatchlisted())->toBeEmpty();

    $watchlist->items()->create(['title_id' => $title->id, 'position' => 1]);

    expect(app(UpNextCache::class)->recentlyWatchlisted()->pluck('id'))->toContain($title->id);

    $watchlist->items()->where('title_id', $title->id)->get()->each->delete();

    expect(app(UpNextCache::class)->recentlyWatchlisted())->toBeEmpty();
});

test('importing a season\'s episodes invalidates the cache', function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
    Http::preventStrayRequests();

    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);
    Follow::factory()->for($title)->create();

    expect(app(UpNextCache::class)->continueWatching())->toBeEmpty();

    Http::fake(['*/tv/1396*' => Http::response(seasonOneFixtureForWatchCache())]);

    (new ImportSeasonEpisodes($title->id, 1))->handle(app(TmdbClient::class));

    expect(app(UpNextCache::class)->continueWatching())->toHaveCount(1);
});

test('the cache key rolls over at local midnight without an explicit bust', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    Carbon::setTestNow('2026-01-01 12:00:00');
    $dayOneKey = app(UpNextCache::class)->key();

    Carbon::setTestNow('2026-01-02 12:00:00');
    $dayTwoKey = app(UpNextCache::class)->key();

    expect($dayOneKey)->not->toBe($dayTwoKey);

    Carbon::setTestNow();
});

test('the cache key changes when the viewer\'s display timezone differs', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));
    $utcKey = app(UpNextCache::class)->key();

    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));
    $nyKey = app(UpNextCache::class)->key();

    expect($utcKey)->not->toBe($nyKey);
});

test('the upnext:warm command populates the cache', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $show->id, 'season_number' => 1]);
    Follow::factory()->for($show)->create();

    Cache::flush();
    expect(Cache::has(app(UpNextCache::class)->key()))->toBeFalse();

    $this->artisan('upnext:warm')->assertSuccessful();

    expect(Cache::has(app(UpNextCache::class)->key()))->toBeTrue();
    expect(app(UpNextCache::class)->continueWatching())->toHaveCount(1);
});
