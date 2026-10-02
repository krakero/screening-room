<?php

use App\Models\Episode;
use App\Models\Follow;
use App\Models\PlexItem;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;

/**
 * FIX-LL1: LazyLoadingViolationException (Episode::plexItem) surfaced on the temporary
 * /title-lab page (since folded into the real Title page — see TL-6) while the
 * `Model::preventLazyLoading()` guard was (temporarily, and now restored) off. Laravel only
 * propagates the "prevents lazy loading" flag onto a model instance when it's hydrated as part
 * of a *multi-row* result (Illuminate\Database\Eloquent\Builder::hydrate() only sets it when
 * `count($items) > 1`) — so every fixture below creates at least two episodes for the relevant
 * show, otherwise these tests would pass even with the bug still present.
 */
test('the real Title page Up next card renders with a cached Plex item, guard on', function () {
    $this->actingAs(User::factory()->create());

    $show = Title::factory()->show()->create(['in_production' => true]);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $nextEpisode = Episode::factory()->aired()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'episode_number' => 2]);
    PlexItem::factory()->for($nextEpisode, 'plexable')->create();

    $response = $this->get(route('titles.show', $show));

    $response->assertOk();
    $response->assertSee(__('Up next'));
});

test('the real Title page renders for a movie with a cached Plex item, guard on', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create();
    PlexItem::factory()->for($movie, 'plexable')->create();

    // A second movie with its own cached Plex item so a batched multi-row hydration path
    // (e.g. a future listing reusing the same eager-loaded `plexItem` relation) would also
    // exercise the guard quirk explained above, not just this single-title page render.
    $otherMovie = Title::factory()->movie()->create();
    PlexItem::factory()->for($otherMovie, 'plexable')->create();

    $response = $this->get(route('titles.show', $movie));

    $response->assertOk();
    $response->assertSee($movie->name);
});

test('the dashboard "Airing This Week" shelf renders with a cached Plex item, guard on', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $showA = Title::factory()->show()->create();
    $seasonA = Season::factory()->for($showA)->create(['season_number' => 1]);
    $airingEpisodeA = Episode::factory()->for($seasonA)->create(['title_id' => $showA->id, 'season_number' => 1, 'episode_number' => 1, 'air_date' => now()->addDays(2)]);
    Follow::factory()->for($showA)->create();
    PlexItem::factory()->for($airingEpisodeA, 'plexable')->create();

    // A second followed show with its own airing-this-week episode so AiringThisWeekQuery's
    // `Episode::whereIn(...)->get()` hydrates more than one row at once (see the guard quirk
    // explained above) — otherwise this test can't actually exercise the guard.
    $showB = Title::factory()->show()->create();
    $seasonB = Season::factory()->for($showB)->create(['season_number' => 1]);
    Episode::factory()->for($seasonB)->create(['title_id' => $showB->id, 'season_number' => 1, 'episode_number' => 1, 'air_date' => now()->addDays(3)]);
    Follow::factory()->for($showB)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(__('Airing This Week'));
});

test('the Season page renders with a cached Plex item, guard on', function () {
    $this->actingAs(User::factory()->create());

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'episode_number' => 2]);
    PlexItem::factory()->for($episode, 'plexable')->create();

    $response = $this->get(route('titles.seasons.show', [$show, 1]));

    $response->assertOk();
});
