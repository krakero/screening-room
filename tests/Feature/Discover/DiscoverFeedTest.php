<?php

use App\Enums\PlaySource;
use App\Enums\TitleType;
use App\Jobs\WarmDiscoverFeed;
use App\Models\Follow;
use App\Models\LibraryStatus;
use App\Models\MediaList;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Title;
use App\Services\Discover\DiscoverFeed;
use App\Services\Discover\DiscoverItem;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
    config(['services.tmdb.region' => 'US']);

    Http::preventStrayRequests();
});

function discoverFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/Fixtures/tmdb/{$name}.json")),
        true,
    );
}

test('trending excludes non movie/tv results and marks in-library titles', function () {
    Http::fake([
        '*/trending/all/week*' => Http::response(discoverFixture('trending_all_week')),
    ]);

    $inLibrary = Title::factory()->show()->create(['tmdb_id' => 1396]);
    LibraryStatus::factory()->available()->create(['title_id' => $inLibrary->id]);

    $items = app(DiscoverFeed::class)->trending();

    expect($items)->toHaveCount(2)
        ->and(collect($items)->pluck('tmdbId')->all())->toBe([603, 1396]);

    $breakingBad = collect($items)->firstWhere('tmdbId', 1396);

    expect($breakingBad->title?->is($inLibrary))->toBeTrue()
        ->and($breakingBad->status)->toBe('available');
});

test('trending caches the tmdb response', function () {
    Http::fake([
        '*/trending/all/week*' => Http::response(discoverFixture('trending_all_week')),
    ]);

    app(DiscoverFeed::class)->trending();
    app(DiscoverFeed::class)->trending();

    Http::assertSentCount(1);
});

test('in theaters and coming soon movies are deduped and sorted by release date', function () {
    Http::fake([
        '*/movie/now_playing*' => Http::response(discoverFixture('movie_now_playing')),
        '*/movie/upcoming*' => Http::response(discoverFixture('movie_upcoming')),
    ]);

    $items = app(DiscoverFeed::class)->inTheatersAndComingSoon();

    expect($items)->toHaveCount(3)
        ->and(collect($items)->pluck('tmdbId')->all())->toBe([603, 100001, 100002]);
});

test('in theaters and coming soon marks a title as a re-release when its original release date is over a year old', function () {
    Http::fake([
        '*/movie/now_playing*' => Http::response(discoverFixture('movie_now_playing_with_re_release')),
        '*/movie/upcoming*' => Http::response(discoverFixture('movie_upcoming')),
    ]);

    $items = collect(app(DiscoverFeed::class)->inTheatersAndComingSoon())->keyBy('tmdbId');

    expect($items->get(200001)->isReRelease)->toBeTrue()
        ->and($items->get(200002)->isReRelease)->toBeFalse()
        ->and($items->get(200003)->isReRelease)->toBeFalse()
        ->and($items->get(100002)->isReRelease)->toBeFalse();
});

test('the trending shelf never marks items as re-releases', function () {
    Http::fake([
        '*/trending/all/week*' => Http::response(discoverFixture('trending_all_week')),
    ]);

    $items = app(DiscoverFeed::class)->trending();

    expect(collect($items)->every(fn (DiscoverItem $item): bool => ! $item->isReRelease))->toBeTrue();
});

test('new episodes this week lists on the air shows', function () {
    Http::fake([
        '*/tv/on_the_air*' => Http::response(discoverFixture('tv_on_the_air')),
    ]);

    $items = app(DiscoverFeed::class)->newEpisodesThisWeek();

    expect($items)->toHaveCount(2)
        ->and($items[0]->type)->toBe(TitleType::Show);
});

test('because you watched builds a shelf per recently played or highly rated title', function () {
    $movie = Title::factory()->movie()->create(['tmdb_id' => 603, 'name' => 'The Matrix']);
    $show = Title::factory()->show()->create(['tmdb_id' => 1396, 'name' => 'Breaking Bad']);

    Play::factory()->create([
        'playable_type' => (new Title)->getMorphClass(),
        'playable_id' => $movie->id,
        'watched_at' => now()->subDays(1),
        'source' => PlaySource::Manual,
    ]);

    Rating::factory()->create([
        'rateable_type' => (new Title)->getMorphClass(),
        'rateable_id' => $show->id,
        'score' => 8,
        'created_at' => now()->subDays(2),
    ]);

    Http::fake([
        '*/movie/603/recommendations*' => Http::response(discoverFixture('movie_603_recommendations')),
        '*/tv/1396/recommendations*' => Http::response(discoverFixture('tv_1396_recommendations')),
    ]);

    $shelves = app(DiscoverFeed::class)->becauseYouWatched();

    expect($shelves)->toHaveCount(2)
        ->and($shelves[0]->heading)->toBe('Because you watched The Matrix')
        ->and($shelves[0]->items)->toHaveCount(2)
        ->and($shelves[1]->heading)->toBe('Because you watched Breaking Bad')
        ->and($shelves[1]->items)->toHaveCount(1);
});

test('because you watched excludes recommendations that are watched, on a list, or followed', function () {
    $movie = Title::factory()->movie()->create(['tmdb_id' => 603, 'name' => 'The Matrix']);

    Play::factory()->create([
        'playable_type' => (new Title)->getMorphClass(),
        'playable_id' => $movie->id,
        'watched_at' => now()->subDay(),
    ]);

    $watched = Title::factory()->movie()->create(['tmdb_id' => 300001]);
    Play::factory()->create([
        'playable_type' => (new Title)->getMorphClass(),
        'playable_id' => $watched->id,
        'watched_at' => now()->subDay(),
    ]);

    $onList = Title::factory()->movie()->create(['tmdb_id' => 300002]);
    MediaList::factory()->create()->titles()->attach($onList, ['position' => 0]);

    Http::fake([
        '*/movie/603/recommendations*' => Http::response(discoverFixture('movie_603_recommendations')),
        '*/movie/300001/recommendations*' => Http::response(['results' => []]),
    ]);

    $shelves = app(DiscoverFeed::class)->becauseYouWatched();

    expect($shelves)->toBe([]);
});

test('because you watched still recommends a title that has merely been imported but is not watched, listed, or followed', function () {
    $movie = Title::factory()->movie()->create(['tmdb_id' => 603, 'name' => 'The Matrix']);

    Play::factory()->create([
        'playable_type' => (new Title)->getMorphClass(),
        'playable_id' => $movie->id,
        'watched_at' => now()->subDay(),
    ]);

    Title::factory()->movie()->create(['tmdb_id' => 300001]);

    Http::fake([
        '*/movie/603/recommendations*' => Http::response(discoverFixture('movie_603_recommendations')),
    ]);

    $shelves = app(DiscoverFeed::class)->becauseYouWatched();

    expect($shelves)->toHaveCount(1)
        ->and($shelves[0]->items)->toHaveCount(2);
});

test('because you watched excludes a followed recommendation even without a play', function () {
    $movie = Title::factory()->movie()->create(['tmdb_id' => 603, 'name' => 'The Matrix']);

    Play::factory()->create([
        'playable_type' => (new Title)->getMorphClass(),
        'playable_id' => $movie->id,
        'watched_at' => now()->subDay(),
    ]);

    $followed = Title::factory()->movie()->create(['tmdb_id' => 300001]);
    Follow::factory()->for($followed)->create();

    Http::fake([
        '*/movie/603/recommendations*' => Http::response(discoverFixture('movie_603_recommendations')),
    ]);

    $shelves = app(DiscoverFeed::class)->becauseYouWatched();

    expect($shelves)->toHaveCount(1)
        ->and($shelves[0]->items)->toHaveCount(1)
        ->and($shelves[0]->items[0]->tmdbId)->toBe(300002);
});

test('because you watched ignores seeds older than 90 days', function () {
    $movie = Title::factory()->movie()->create(['tmdb_id' => 603, 'name' => 'The Matrix']);

    Play::factory()->create([
        'playable_type' => (new Title)->getMorphClass(),
        'playable_id' => $movie->id,
        'watched_at' => now()->subDays(91),
    ]);

    $shelves = app(DiscoverFeed::class)->becauseYouWatched();

    expect($shelves)->toBe([]);

    Http::assertNothingSent();
});

test('because you watched caps at three shelves', function () {
    $titles = collect(range(1, 4))->map(fn (int $i) => Title::factory()->movie()->create([
        'tmdb_id' => 700000 + $i,
        'name' => "Watched {$i}",
    ]));

    $titles->each(function (Title $title, int $index) {
        Play::factory()->create([
            'playable_type' => (new Title)->getMorphClass(),
            'playable_id' => $title->id,
            'watched_at' => now()->subDays($index + 1),
        ]);
    });

    Http::fake(fn () => Http::response(['results' => []]));

    $shelves = app(DiscoverFeed::class)->becauseYouWatched();

    Http::assertSentCount(3);
    expect($shelves)->toBe([]);
});

function fakeAllDiscoverEndpoints(): void
{
    Http::fake([
        '*/trending/all/week*' => Http::response(discoverFixture('trending_all_week')),
        '*/movie/now_playing*' => Http::response(discoverFixture('movie_now_playing')),
        '*/movie/upcoming*' => Http::response(discoverFixture('movie_upcoming')),
        '*/tv/on_the_air*' => Http::response(discoverFixture('tv_on_the_air')),
        '*/movie/603/recommendations*' => Http::response(discoverFixture('movie_603_recommendations')),
    ]);
}

function seedRecentlyWatchedMovie(): Title
{
    $movie = Title::factory()->movie()->create(['tmdb_id' => 603]);

    Play::factory()->create([
        'playable_type' => (new Title)->getMorphClass(),
        'playable_id' => $movie->id,
        'watched_at' => now()->subDay(),
        'source' => PlaySource::Manual,
    ]);

    return $movie;
}

test('the warm job populates every discover cache key', function () {
    seedRecentlyWatchedMovie();
    fakeAllDiscoverEndpoints();

    (new WarmDiscoverFeed)->handle(app(DiscoverFeed::class));

    foreach ([
        'discover:trending:all:week',
        'discover:now_playing:US',
        'discover:upcoming:US',
        'discover:on_the_air',
        'discover:recommendations:movie:603',
    ] as $key) {
        expect(Cache::has($key))->toBeTrue("{$key} was not warmed");
    }
});

test('discover reads after warming make no http calls', function () {
    seedRecentlyWatchedMovie();
    fakeAllDiscoverEndpoints();

    app(DiscoverFeed::class)->warm();
    Http::assertSentCount(5);

    $feed = app(DiscoverFeed::class);
    $feed->trending();
    $feed->inTheatersAndComingSoon();
    $feed->newEpisodesThisWeek();
    $feed->becauseYouWatched();

    Http::assertSentCount(5);
});

test('warming overwrites a stale cached payload', function () {
    Cache::put('discover:trending:all:week', ['results' => []], now()->addHour());
    fakeAllDiscoverEndpoints();

    app(DiscoverFeed::class)->warm();

    expect(Cache::get('discover:trending:all:week')['results'])->not->toBeEmpty();
});

test('a failing endpoint does not stop the other sources from warming', function () {
    Http::fake([
        '*/trending/all/week*' => Http::response(['status_message' => 'boom'], 500),
        '*/movie/now_playing*' => Http::response(discoverFixture('movie_now_playing')),
        '*/movie/upcoming*' => Http::response(discoverFixture('movie_upcoming')),
        '*/tv/on_the_air*' => Http::response(discoverFixture('tv_on_the_air')),
    ]);

    app(DiscoverFeed::class)->warm();

    expect(Cache::has('discover:trending:all:week'))->toBeFalse()
        ->and(Cache::has('discover:now_playing:US'))->toBeTrue()
        ->and(Cache::has('discover:upcoming:US'))->toBeTrue()
        ->and(Cache::has('discover:on_the_air'))->toBeTrue();
});

test('a failing source during warm keeps its existing cached value', function () {
    Http::fake([
        '*/trending/all/week*' => Http::response(discoverFixture('trending_all_week')),
    ]);
    app(DiscoverFeed::class)->trending();

    $before = Cache::get('discover:trending:all:week');

    Http::fake([
        '*/trending/all/week*' => Http::response(['status_message' => 'boom'], 500),
        '*/movie/now_playing*' => Http::response(discoverFixture('movie_now_playing')),
        '*/movie/upcoming*' => Http::response(discoverFixture('movie_upcoming')),
        '*/tv/on_the_air*' => Http::response(discoverFixture('tv_on_the_air')),
    ]);

    app(DiscoverFeed::class)->warm();

    expect(Cache::get('discover:trending:all:week'))->toBe($before);
});

test('a read within the fresh window makes no http call', function () {
    Http::fake([
        '*/trending/all/week*' => Http::response(discoverFixture('trending_all_week')),
    ]);

    app(DiscoverFeed::class)->trending();

    $this->travel(5)->hours();
    app(DiscoverFeed::class)->trending();

    Http::assertSentCount(1);
});

test('a read in the stale window returns the cached data immediately and defers a refresh', function () {
    Http::fake([
        '*/trending/all/week*' => Http::response(discoverFixture('trending_all_week')),
    ]);

    app(DiscoverFeed::class)->trending();
    Http::assertSentCount(1);

    $this->travel(12)->hours();

    $items = app(DiscoverFeed::class)->trending();
    expect($items)->toHaveCount(2);
    Http::assertSentCount(1);

    app(DeferredCallbackCollection::class)->invoke();
    Http::assertSentCount(2);
});

test('a failed deferred refresh in the stale window keeps serving the existing value', function () {
    Http::fake([
        '*/trending/all/week*' => Http::response(discoverFixture('trending_all_week')),
    ]);

    app(DiscoverFeed::class)->trending();

    $this->travel(12)->hours();

    Http::fake([
        '*/trending/all/week*' => Http::response(['status_message' => 'boom'], 500),
    ]);

    app(DiscoverFeed::class)->trending();
    app(DeferredCallbackCollection::class)->invoke();

    expect(Cache::get('discover:trending:all:week')['results'])->not->toBeEmpty();
});

test('warming leaves subsequent reads fresh with no deferred refresh', function () {
    seedRecentlyWatchedMovie();
    fakeAllDiscoverEndpoints();

    app(DiscoverFeed::class)->warm();
    Http::assertSentCount(5);

    $this->travel(5)->hours();

    app(DiscoverFeed::class)->trending();
    app(DeferredCallbackCollection::class)->invoke();

    Http::assertSentCount(5);
});

test('the discover:warm command warms every discover cache key', function () {
    seedRecentlyWatchedMovie();
    fakeAllDiscoverEndpoints();

    $this->artisan('discover:warm')->assertSuccessful();

    foreach ([
        'discover:trending:all:week',
        'discover:now_playing:US',
        'discover:upcoming:US',
        'discover:on_the_air',
        'discover:recommendations:movie:603',
    ] as $key) {
        expect(Cache::has($key))->toBeTrue("{$key} was not warmed");
    }
});
