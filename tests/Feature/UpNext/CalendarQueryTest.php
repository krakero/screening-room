<?php

use App\Enums\CalendarEntryType;
use App\Enums\FollowState;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\MediaList;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\CalendarQuery;
use Illuminate\Support\Carbon;

test('it includes episodes of followed shows in range and excludes abandoned follows', function () {
    $followedShow = Title::factory()->show()->create();
    $season = Season::factory()->for($followedShow)->create(['season_number' => 1]);
    Follow::factory()->for($followedShow)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $followedShow->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->addDays(2),
    ]);

    $abandonedShow = Title::factory()->show()->create();
    $abandonedSeason = Season::factory()->for($abandonedShow)->create(['season_number' => 1]);
    Follow::factory()->for($abandonedShow)->create(['state' => FollowState::Abandoned]);
    Episode::factory()->for($abandonedSeason)->create([
        'title_id' => $abandonedShow->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->addDays(2),
    ]);

    $entries = app(CalendarQuery::class)->forRange(Carbon::today(), Carbon::today()->addDays(7));

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->type)->toBe(CalendarEntryType::Episode)
        ->and($entries->first()->episode->is($episode))->toBeTrue();
});

test('it excludes season 0 specials from followed episodes', function () {
    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 0]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    Episode::factory()->for($season)->special()->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->addDay(),
    ]);

    $entries = app(CalendarQuery::class)->forRange(Carbon::today(), Carbon::today()->addDays(7));

    expect($entries)->toBeEmpty();
});

test('it includes watchlist movie release dates', function () {
    $movie = Title::factory()->movie()->create(['release_date' => Carbon::today()->addDays(3)]);

    MediaList::watchlist()->titles()->attach($movie->id, ['position' => 0]);

    $entries = app(CalendarQuery::class)->forRange(Carbon::today(), Carbon::today()->addDays(7));

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->type)->toBe(CalendarEntryType::MovieRelease)
        ->and($entries->first()->title->is($movie))->toBeTrue();
});

test('it includes season premieres for watchlist shows that have not been started', function () {
    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    MediaList::watchlist()->titles()->attach($show->id, ['position' => 0]);

    $premiere = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->addDays(5),
    ]);

    $entries = app(CalendarQuery::class)->forRange(Carbon::today(), Carbon::today()->addDays(7));

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->type)->toBe(CalendarEntryType::SeasonPremiere)
        ->and($entries->first()->episode->is($premiere))->toBeTrue();
});

test('it excludes season premieres for watchlist shows that have already been started', function () {
    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    MediaList::watchlist()->titles()->attach($show->id, ['position' => 0]);

    $watchedEpisode = Episode::factory()->for($season)->aired()->create([
        'title_id' => $show->id,
        'episode_number' => 1,
    ]);
    $watchedEpisode->plays()->create(['watched_at' => now(), 'source' => 'manual']);

    Episode::factory()->for(Season::factory()->for($show)->create(['season_number' => 2]))->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->addDays(5),
    ]);

    $entries = app(CalendarQuery::class)->forRange(Carbon::today(), Carbon::today()->addDays(7));

    // Logging the play auto-follows the show (PlayObserver), so its upcoming episode now
    // surfaces as a regular followed episode instead of a "not started yet" season premiere.
    expect($entries->contains(fn ($entry): bool => $entry->type === CalendarEntryType::SeasonPremiere))->toBeFalse();
});

test('catch up returns unwatched episodes from the last 7 days for followed shows only', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $unwatched = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->subDays(2),
    ]);

    $watched = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 2,
        'air_date' => Carbon::today()->subDays(3),
    ]);
    $watched->plays()->create(['watched_at' => now(), 'source' => 'manual']);

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 3,
        'air_date' => Carbon::today()->subDays(10),
    ]);

    $entries = app(CalendarQuery::class)->catchUp();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->episode->is($unwatched))->toBeTrue();
});

test('airing today returns only episodes airing today for followed shows', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $today = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today(),
    ]);

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 2,
        'air_date' => Carbon::today()->addDay(),
    ]);

    $entries = app(CalendarQuery::class)->airingToday();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->episode->is($today))->toBeTrue();
});
