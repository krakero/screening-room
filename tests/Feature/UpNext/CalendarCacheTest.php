<?php

use App\Actions\Follows\PauseShow;
use App\Actions\Plays\LogPlay;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\CalendarCache;
use App\Support\DisplayTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('a cache miss computes and caches; a cache hit skips the query service and only pays for cheap hydration', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));
    $today = DisplayTimezone::today();

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'season_number' => 1,
        'air_date' => $today->clone()->addDay(),
    ]);
    Follow::factory()->for($show)->create();

    $first = app(CalendarCache::class)->forRange($today, $today->clone()->addDays(7));
    expect($first)->toHaveCount(1);

    DB::enableQueryLog();
    $second = app(CalendarCache::class)->forRange($today, $today->clone()->addDays(7));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // A hit still pays for hydrating the cached rows (titles + episodes, each with their
    // plexItem eager load — 4 cheap indexed queries) but never re-runs CalendarQuery's
    // several underlying queries (episodes, movie releases, season premieres, watched-status)
    // again.
    expect($second)->toHaveCount(1);
    expect($queryCount)->toBeLessThanOrEqual(4);
});

test('logging a play on a calendar entry invalidates the cache', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));
    $today = DisplayTimezone::today();

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'season_number' => 1,
        'air_date' => $today->clone()->addDay(),
    ]);
    Follow::factory()->for($show)->create();

    $range = [$today, $today->clone()->addDays(7)];

    expect(app(CalendarCache::class)->forRange(...$range)->first()->watched)->toBeFalse();

    app(LogPlay::class)->handle($episode);

    expect(app(CalendarCache::class)->forRange(...$range)->first()->watched)->toBeTrue();
});

test('pausing a follow invalidates the cache', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));
    $today = DisplayTimezone::today();

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'season_number' => 1,
        'air_date' => $today->clone()->addDay(),
    ]);
    $follow = Follow::factory()->for($show)->create();

    $range = [$today, $today->clone()->addDays(7)];

    expect(app(CalendarCache::class)->forRange(...$range))->toHaveCount(1);

    app(PauseShow::class)->handle($follow);

    expect(app(CalendarCache::class)->forRange(...$range))->toBeEmpty();
});

test('catch-up invalidates the same way as forRange', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));
    $today = DisplayTimezone::today();

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'season_number' => 1,
        'air_date' => $today->clone()->subDays(2),
    ]);
    Follow::factory()->for($show)->create();

    expect(app(CalendarCache::class)->catchUp())->toHaveCount(1);

    app(LogPlay::class)->handle($episode);

    expect(app(CalendarCache::class)->catchUp())->toBeEmpty();
});

test('different ranges (agenda vs. month) are cached independently', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));
    $today = DisplayTimezone::today();

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'season_number' => 1,
        'air_date' => $today->clone()->addDays(45),
    ]);
    Follow::factory()->for($show)->create();

    $agenda = app(CalendarCache::class)->forRange($today, $today->clone()->addDays(60));
    $narrowRange = app(CalendarCache::class)->forRange($today, $today->clone()->addDays(7));

    expect($agenda)->toHaveCount(1);
    expect($narrowRange)->toBeEmpty();
});

test('catch-up (which has no range params of its own) rolls over at local midnight without an explicit bust', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'season_number' => 1,
        'air_date' => Carbon::parse('2026-01-05'),
    ]);
    Follow::factory()->for($show)->create();

    // 2026-01-06: the episode aired yesterday, so it's in the catch-up window.
    Carbon::setTestNow('2026-01-06 12:00:00');
    expect(app(CalendarCache::class)->catchUp())->toHaveCount(1);

    // 2026-01-20: the episode aired 15 days ago, outside the 7-day catch-up window — this
    // only reflects reality if the cached entry from 01-06 wasn't reused past midnight.
    Carbon::setTestNow('2026-01-20 12:00:00');
    expect(app(CalendarCache::class)->catchUp())->toBeEmpty();

    Carbon::setTestNow();
});

test('the upnext:warm command populates the default calendar views', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));
    $today = DisplayTimezone::today();

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'season_number' => 1,
        'air_date' => $today->clone()->addDay(),
    ]);
    Follow::factory()->for($show)->create();

    $this->artisan('upnext:warm')->assertSuccessful();

    DB::enableQueryLog();
    $agenda = app(CalendarCache::class)->forRange($today, $today->clone()->addDays(60));
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($agenda)->toHaveCount(1);
    // Reading the exact range the command warmed is a cache hit — cheap hydration only.
    expect($queryCount)->toBeLessThanOrEqual(4);
});
