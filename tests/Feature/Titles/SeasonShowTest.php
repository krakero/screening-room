<?php

use App\Actions\Follows\RestartShow;
use App\Enums\PlaySource;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);

    $this->get(route('titles.seasons.show', [$title, $season->season_number]))->assertRedirect(route('login'));
});

test('it renders a loaded season with its episodes', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'Severance']);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'name' => 'Season One']);
    Episode::factory()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'name' => 'Good News About Hell']);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertSee('Severance');
    $response->assertSee('Good News About Hell');
    $response->assertDontSee('@if');
});

test('an unloaded season renders a loading skeleton and loads episodes via wire:init', function () {
    $this->actingAs(User::factory()->create());

    // Prevents mount()'s trailer-refresh dispatch (which also targets TMDB, per this
    // test's config below) from actually running on the sync queue and colliding with
    // the fake response set up further down for the episode-import fetch.
    Queue::fake();

    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create(['tmdb_id' => 4100]);
    $season = Season::factory()->notLoaded()->for($title)->create(['season_number' => 1]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));
    $response->assertOk();
    $response->assertSee(__('Loading episodes…'));

    Http::fake([
        '*/tv/4100*' => Http::response([
            'id' => 4100,
            'season/1' => [
                'id' => 6001,
                'season_number' => 1,
                'episodes' => [
                    ['id' => 910001, 'episode_number' => 1, 'season_number' => 1, 'name' => 'Pilot', 'air_date' => '2020-01-01'],
                ],
            ],
        ]),
    ]);

    $component = Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('loadEpisodes');

    $component->assertSee('Pilot')
        ->assertDontSee(__('Loading episodes…'));

    expect($season->fresh()->episodesLoaded())->toBeTrue();
});

test('toggling an unwatched aired episode logs a manual play', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('toggleEpisode', $episode->id);

    expect($episode->plays()->count())->toBe(1)
        ->and($episode->plays()->first()->source)->toBe(PlaySource::Manual);
});

test('toggling a watched episode removes the latest manual play', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);
    Play::factory()->for($episode, 'playable')->create(['source' => PlaySource::Manual, 'watched_at' => now()->subDay()]);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('toggleEpisode', $episode->id);

    expect($episode->plays()->count())->toBe(0);
});

test('toggling an episode belonging to another season is rejected', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $otherSeason = Season::factory()->for($title)->create(['season_number' => 2]);
    $otherEpisode = Episode::factory()->for($otherSeason)->create(['title_id' => $title->id]);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('toggleEpisode', $otherEpisode->id)
        ->assertStatus(404);
});

test('marking a season watched logs plays for aired unwatched episodes only', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);

    $alreadyWatched = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);
    Play::factory()->for($alreadyWatched, 'playable')->create();

    $unwatchedAired = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);
    $unaired = Episode::factory()->unaired()->for($season)->create(['title_id' => $title->id]);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('markSeasonWatched');

    expect($alreadyWatched->plays()->count())->toBe(1)
        ->and($unwatchedAired->plays()->count())->toBe(1)
        ->and($unaired->plays()->count())->toBe(0);
});

test('toggling an episode with a custom datetime logs a play at that time', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('confirmCustomDatetime', 'episode:'.$episode->id, '2017-08-08T21:15');

    expect($episode->plays()->sole()->watched_at->format('Y-m-d H:i'))->toBe('2017-08-08 21:15');
});

test('it links to the previous and next season', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    Season::factory()->for($title)->create(['season_number' => 2]);
    Season::factory()->for($title)->create(['season_number' => 3]);

    $response = $this->get(route('titles.seasons.show', [$title, 2]));

    $response->assertOk();
    $response->assertSee(route('titles.seasons.show', [$title, 1]), false);
    $response->assertSee(route('titles.seasons.show', [$title, 3]), false);
});

test('episodes render as a grid of episode cards', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'Severance']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1, 'name' => 'Good News About Hell']);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', false);
    $response->assertSee('aspect-video', false);
    $response->assertSee('S01E01', false);
});

test('episode cards link to the current page with ?episode=', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertSee(route('titles.seasons.show', [$title, 1]).'?episode='.$episode->id, false);
});

test('toggling watched from the flyout refreshes the season page episode list', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    $component = Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1]);

    expect($component->instance()->episodes->firstWhere('id', $episode->id)->plays)->toBeEmpty();

    Play::factory()->for($episode, 'playable')->create();
    $component->call('refreshEpisodes');

    expect($component->instance()->episodes->firstWhere('id', $episode->id)->plays)->toHaveCount(1);
});

test('an unaired episode has no watched toggle and is muted', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->unaired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1, 'name' => 'Not Yet']);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('opacity-60', false);
    $response->assertDontSee(__('Mark watched'));
});

test('an aired unwatched episode shows a mark-watched menu item in the actions menu', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(__('Mark watched'));
    $response->assertSee('$wire.toggleEpisode('.$episode->id.')', false);
});

test('an aired unwatched episode card wires up the mark-season-watched bulk-flip listener, and the confirm button dispatches it', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('x-on:mark-season-watched.window="bulkSet(true)"', false);
    $response->assertSee('x-on:click="$dispatch(\'mark-season-watched\')"', false);
});

test('an aired unwatched episode wires the watched-on submenu through the optimistic helper, not wire:click', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('x-show="!value" x-on:click="set(true, () => $wire.toggleEpisode('.$episode->id.', &#039;release_date&#039;))"', false);
    $response->assertSee('x-show="!value" x-on:click="set(true, () => $wire.toggleEpisode('.$episode->id.', &#039;unknown&#039;))"', false);
    $response->assertDontSee(sprintf('wire:click="toggleEpisode(%d, \'release_date\')"', $episode->id), false);
    $response->assertDontSee(sprintf('wire:click="toggleEpisode(%d, \'unknown\')"', $episode->id), false);
});

test('an aired unwatched episode card wires up the watched-custom-flip listener for its own pick-datetime target', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('x-on:watched-custom-flip.window="if ($event.detail.target === \'episode:'.$episode->id.'\') { bulkSet(true) }"', false);
    $response->assertSee('x-on:watched-custom-flip-revert.window="if ($event.detail.target === \'episode:'.$episode->id.'\') { bulkSet(false) }"', false);
});

test('the custom-datetime modal Save closes instantly, flips a season bulk event for a season target, and calls $wire directly', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('wire:submit="confirmCustomDatetime(customDatetimeTarget, customDatetime)"', false);
    $response->assertSee("\$flux.modal('custom-watched-at').close();", false);
    $response->assertSee("if (customDatetimeTarget === 'season') {", false);
    $response->assertSee("\$dispatch('mark-season-watched');", false);
    $response->assertSee('$wire.confirmCustomDatetime(customDatetimeTarget, customDatetime).catch', false);
});

test('a watched episode with a manual play shows a clickable unmark menu item without a confirm prompt', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create(['source' => PlaySource::Manual]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('wire:click="toggleEpisode('.$episode->id.')"', false);
    $response->assertDontSee('wire:confirm', false);
});

test('a watched episode without a manual play shows an unmark menu item with a confirm prompt', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create(['source' => PlaySource::Plex]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('wire:click="toggleEpisode('.$episode->id.')"', false);
    $response->assertSee(__('This episode was watched via Plex/Trakt, not logged manually here. Remove it anyway?'));
});

test('an aired episode shows a Watch on Plex menu item when available', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    $title = Title::factory()->show()->create();
    $title->plexItem()->create(['rating_key' => '501', 'machine_identifier' => 'abc123', 'checked_at' => now()]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    $episode->plexItem()->create(['rating_key' => '901', 'machine_identifier' => 'abc123', 'checked_at' => now()]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(__('Watch on Plex'));
    $response->assertDontSee('Play on Plex');
});

test('the up next episode shows an "Up Next" badge in the still\'s top-left chip position', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $nextEpisode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 2]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSeeInOrder(['left-1.5 top-1.5', __('Up Next'), 'S01E01', 'S01E02'], false);
});

test('clicking mark season watched opens a confirmation modal without logging plays', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('confirmMarkSeasonWatched')
        ->assertSee(__('Mark all :count aired episodes of :season as watched?', ['count' => 1, 'season' => 'Season 1']));

    expect($episode->plays()->count())->toBe(0);
});

test('confirming the season watched modal logs plays for aired unwatched episodes', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id]);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('confirmMarkSeasonWatched')
        ->call('confirmedMarkSeasonWatched');

    expect($episode->plays()->count())->toBe(1);
});

test('a season belonging to another title 404s', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $otherTitle = Title::factory()->show()->create();
    Season::factory()->for($otherTitle)->create(['season_number' => 1]);

    $this->get(route('titles.seasons.show', [$title, 1]))->assertStatus(404);
});

test('while rewatching, an episode watched only before the restart shows as unwatched', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create(['watched_at' => now()->subYear()]);

    app(RestartShow::class)->handle($title);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(__('Mark watched'));
});

test('while rewatching, marking an episode watched adds a new play instead of removing the old one', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create(['watched_at' => now()->subYear()]);

    app(RestartShow::class)->handle($title);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('toggleEpisode', $episode->id);

    expect($episode->plays()->count())->toBe(2);
});

test('while rewatching, unmarking an episode watched removes the play logged since the restart, not the older one', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    $oldPlay = Play::factory()->for($episode, 'playable')->create(['watched_at' => now()->subYear()]);

    app(RestartShow::class)->handle($title);
    $newPlay = Play::factory()->for($episode, 'playable')->create(['watched_at' => now()]);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('toggleEpisode', $episode->id);

    expect(Play::query()->whereKey($oldPlay->id)->exists())->toBeTrue()
        ->and(Play::query()->whereKey($newPlay->id)->exists())->toBeFalse();
});

test('while rewatching, the up next badge moves back to S1E1', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode1 = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 2]);
    Play::factory()->for($episode1, 'playable')->create(['watched_at' => now()->subYear()]);

    app(RestartShow::class)->handle($title);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSeeInOrder(['left-1.5 top-1.5', __('Up Next'), 'S01E01', 'S01E02'], false);
});

test('the season page shows a spinning "Checking Plex…" pending state when an aired episode has no cached row', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::preventStrayRequests();
    Queue::fake();

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(__('Checking Plex…'));

    $content = $response->getContent();
    $checkingPos = strpos($content, __('Checking Plex…'));
    expect($checkingPos)->not->toBeFalse();
    expect(substr($content, max(0, $checkingPos - 1500), 1500))->toContain('animate-spin');
});
