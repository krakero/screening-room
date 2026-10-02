<?php

use App\Enums\PlaySource;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('history'));

    $response->assertRedirect(route('login'));
});

test('an empty history shows an empty state', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('history'));

    $response->assertOk();
    $response->assertSee(__('No plays yet'));
});

test('plays are listed chronologically and grouped by day', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['name' => 'Fight Club']);
    $older = Play::factory()->for($movie, 'playable')->create(['watched_at' => now()->subDays(2)]);

    $show = Title::factory()->show()->create(['name' => 'Severance']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'episode_number' => 3, 'name' => 'In Perpetuity']);
    $newer = Play::factory()->for($episode, 'playable')->create(['watched_at' => now()]);

    $response = $this->get(route('history'));

    $response->assertOk();
    $response->assertSeeInOrder(['Severance', 'Fight Club']);
    $response->assertSee(__('Today'));
});

test('plays with an unknown watched_at are grouped at the end under an unknown-date heading', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['name' => 'Fight Club']);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);

    $unknownMovie = Title::factory()->movie()->create(['name' => 'Unknown Movie']);
    Play::factory()->for($unknownMovie, 'playable')->create(['watched_at' => null]);

    $response = $this->get(route('history'));

    $response->assertOk();
    $response->assertSeeInOrder(['Fight Club', __('Unknown date'), 'Unknown Movie']);
});

test('watched dates and times are shown in the user\'s timezone, not UTC', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));

    // 2026-01-02 02:30 UTC is 2026-01-01 9:30pm in America/New_York — a different
    // calendar day and a 12-hour-clock time, if displayed correctly.
    $movie = Title::factory()->movie()->create(['name' => 'Late Night Watch']);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => '2026-01-02 02:30:00']);

    $response = $this->get(route('history'));

    $response->assertOk();
    $response->assertSee('9:30pm');
    $response->assertDontSee('2:30am');
});

test('a play can be removed from history', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    $play = Play::factory()->for($title, 'playable')->create(['source' => PlaySource::Manual]);

    Livewire::test('pages::history')
        ->call('removePlay', $play->id);

    expect(Play::query()->find($play->id))->toBeNull();
});

test('each play links to its title detail page', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    Play::factory()->for($title, 'playable')->create();

    $response = $this->get(route('history'));

    $response->assertOk();
    $response->assertSee(route('titles.show', $title), escape: false);
});

test('episode plays link to the current page with ?episode= and the poster links to the show', function () {
    $this->actingAs(User::factory()->create());

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create();

    $response = $this->get(route('history'));

    $response->assertOk();
    $response->assertSee(route('history').'?episode='.$episode->id, false);
    $response->assertSee(route('titles.show', $show), false);
});

test('movie plays keep linking to the movie title page, not the flyout', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create();
    Play::factory()->for($movie, 'playable')->create();

    $response = $this->get(route('history'));

    $response->assertOk();
    $response->assertDontSee('?episode=', false);
    $response->assertSee(route('titles.show', $movie), false);
});

test('the episode-watched-changed event refreshes the history list', function () {
    $this->actingAs(User::factory()->create());

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create();

    $component = Livewire::test('pages::history');

    expect($component->instance()->plays->total())->toBe(1);

    Play::query()->where('playable_id', $episode->id)->delete();
    $component->call('refreshPlays');

    expect($component->instance()->plays->total())->toBe(0);
});

test('episode plays show the episode still, falling back to the show backdrop', function () {
    config(['services.tmdb.image_base_url' => 'https://image.tmdb.org/t/p']);
    $this->actingAs(User::factory()->create());

    $show = Title::factory()->show()->create(['backdrop_path' => '/show-backdrop.jpg']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $withStill = Episode::factory()->for($season)->create(['title_id' => $show->id, 'episode_number' => 1, 'still_path' => '/episode-still.jpg']);
    $withoutStill = Episode::factory()->for($season)->create(['title_id' => $show->id, 'episode_number' => 2, 'still_path' => null]);

    Play::factory()->for($withStill, 'playable')->create();
    Play::factory()->for($withoutStill, 'playable')->create();

    $this->get(route('history'))
        ->assertOk()
        ->assertSee('https://image.tmdb.org/t/p/w300/episode-still.jpg', false)
        ->assertSee('https://image.tmdb.org/t/p/w300/show-backdrop.jpg', false);
});

test('movie plays show the movie backdrop', function () {
    config(['services.tmdb.image_base_url' => 'https://image.tmdb.org/t/p']);
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['backdrop_path' => '/movie-backdrop.jpg']);
    Play::factory()->for($movie, 'playable')->create();

    $this->get(route('history'))
        ->assertOk()
        ->assertSee('https://image.tmdb.org/t/p/w300/movie-backdrop.jpg', false);
});

test('history renders episode and movie plays as episode cards with the show/movie poster overlapping', function () {
    config(['services.tmdb.image_base_url' => 'https://image.tmdb.org/t/p']);
    $this->actingAs(User::factory()->create());

    $show = Title::factory()->show()->create(['name' => 'Severance', 'poster_path' => '/severance-poster.jpg']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'season_number' => 1,
        'episode_number' => 3,
        'name' => 'In Perpetuity',
    ]);
    Play::factory()->for($episode, 'playable')->create();

    $movie = Title::factory()->movie()->create(['name' => 'Fight Club', 'poster_path' => '/fight-club-poster.jpg', 'release_date' => '1999-10-15']);
    Play::factory()->for($movie, 'playable')->create();

    $response = $this->get(route('history'));

    $response->assertOk();
    $response->assertDontSee('@if', false);
    $response->assertSee('https://image.tmdb.org/t/p/w185/severance-poster.jpg', false);
    $response->assertSee('https://image.tmdb.org/t/p/w185/fight-club-poster.jpg', false);
    $response->assertSee('S01E03');
    $response->assertSee('In Perpetuity');
    $response->assertSee('1999');
});

test('each day renders as a horizontally scrolling shelf of fixed-width cards', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['name' => 'Fight Club']);
    Play::factory()->for($movie, 'playable')->count(2)->create(['watched_at' => now()]);

    $response = $this->get(route('history'));

    $response->assertOk();
    $response->assertDontSee('@if', false);
    $response->assertSee('snap-x snap-mandatory', false);
    $response->assertSee('w-72 shrink-0 snap-start sm:w-80', false);
});

test('history actions (remove/unwatch) still work on the card layout', function () {
    $this->actingAs(User::factory()->create());

    $manualTitle = Title::factory()->movie()->create();
    $manualPlay = Play::factory()->for($manualTitle, 'playable')->create(['source' => PlaySource::Manual]);

    $plexTitle = Title::factory()->movie()->create();
    $plexPlay = Play::factory()->for($plexTitle, 'playable')->create(['source' => PlaySource::Plex]);

    $response = $this->get(route('history'));

    $response->assertOk();
    $response->assertSee('x-on:click="set(true, () => $wire.removePlay('.$manualPlay->id.'))"', false);
    $response->assertSee('$wire.removePlay('.$plexPlay->id.'))"', false);
    $response->assertSee('if (!confirm(', false);
});
