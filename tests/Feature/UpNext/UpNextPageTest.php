<?php

use App\Actions\Follows\RestartShow;
use App\Enums\FollowState;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\UpNext\UpNextCache;
use App\Support\IntegrationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('shows an empty state when nothing is followed or in the library', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(__('Nothing here yet'));
});

test('continue watching shows followed shows with an unwatched aired episode, most recently played first', function () {
    $this->actingAs(User::factory()->create());

    $older = Title::factory()->show()->create(['name' => 'Older Show']);
    $olderSeason = Season::factory()->for($older)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($olderSeason)->create(['title_id' => $older->id, 'season_number' => 1, 'episode_number' => 1, 'name' => 'Old Next']);
    Follow::factory()->for($older)->create(['last_played_at' => now()->subDays(5)]);

    $newer = Title::factory()->show()->create(['name' => 'Newer Show']);
    $newerSeason = Season::factory()->for($newer)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($newerSeason)->create(['title_id' => $newer->id, 'season_number' => 1, 'episode_number' => 5, 'name' => 'New Next']);
    Follow::factory()->for($newer)->create(['last_played_at' => now()->subDay()]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder(['Newer Show', 'Older Show']);
    $response->assertSee('S01E05 · New Next');
});

test('the continue watching card is keyed by episode, not by show, so a Livewire morph to the next episode gets a fresh optimistic scope', function () {
    $this->actingAs(User::factory()->create());

    // Offset the titles table's id sequence so title_id and episode_id can never coincidentally match.
    Title::factory()->count(2)->create();

    $title = Title::factory()->show()->create(['name' => 'Morph Show']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Follow::factory()->for($title)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('wire:key="continue-'.$episode->id.'"', false);
    $response->assertDontSee('wire:key="continue-'.$title->id.'"', false);
});

test('a fully watched in-production show without a new episode is excluded from continue watching', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'All Caught Up', 'in_production' => true]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($episode, 'playable')->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee(__('Continue Watching'));
});

test('airing this week only lists episodes of followed shows airing in the next 7 days', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $followed = Title::factory()->show()->create(['name' => 'Followed Soon']);
    $followedSeason = Season::factory()->for($followed)->create(['season_number' => 1]);
    Episode::factory()->for($followedSeason)->create([
        'title_id' => $followed->id,
        'season_number' => 1,
        'episode_number' => 3,
        'air_date' => Carbon::today()->addDays(3),
    ]);
    Follow::factory()->for($followed)->create();

    $tooFar = Title::factory()->show()->create(['name' => 'Followed Later']);
    $tooFarSeason = Season::factory()->for($tooFar)->create(['season_number' => 1]);
    Episode::factory()->for($tooFarSeason)->create([
        'title_id' => $tooFar->id,
        'season_number' => 1,
        'air_date' => Carbon::today()->addDays(30),
    ]);
    Follow::factory()->for($tooFar)->create();

    $abandoned = Title::factory()->show()->create(['name' => 'Abandoned Airing']);
    $abandonedSeason = Season::factory()->for($abandoned)->create(['season_number' => 1]);
    Episode::factory()->for($abandonedSeason)->create([
        'title_id' => $abandoned->id,
        'season_number' => 1,
        'air_date' => Carbon::today()->addDays(2),
    ]);
    Follow::factory()->abandoned()->for($abandoned)->create();

    $notFollowed = Title::factory()->show()->create(['name' => 'Not Followed']);
    $notFollowedSeason = Season::factory()->for($notFollowed)->create(['season_number' => 1]);
    Episode::factory()->for($notFollowedSeason)->create([
        'title_id' => $notFollowed->id,
        'season_number' => 1,
        'air_date' => Carbon::today()->addDays(2),
    ]);

    $paused = Title::factory()->show()->create(['name' => 'Paused Airing']);
    $pausedSeason = Season::factory()->for($paused)->create(['season_number' => 1]);
    Episode::factory()->for($pausedSeason)->create([
        'title_id' => $paused->id,
        'season_number' => 1,
        'air_date' => Carbon::today()->addDays(2),
    ]);
    Follow::factory()->paused()->for($paused)->create();

    $titleIds = Livewire::test('pages::dashboard')->instance()->airingThisWeek->pluck('title_id');

    expect($titleIds)->toContain($followed->id)
        ->and($titleIds)->not->toContain($tooFar->id)
        ->and($titleIds)->not->toContain($abandoned->id)
        ->and($titleIds)->not->toContain($notFollowed->id)
        ->and($titleIds)->not->toContain($paused->id);
});

test('airing this week hides an episode that has already been watched', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $watchedTitle = Title::factory()->show()->create(['name' => 'Already Watched']);
    $watchedSeason = Season::factory()->for($watchedTitle)->create(['season_number' => 1]);
    $watchedEpisode = Episode::factory()->for($watchedSeason)->create([
        'title_id' => $watchedTitle->id,
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => Carbon::today()->addDays(2),
    ]);
    Play::factory()->for($watchedEpisode, 'playable')->create();

    $unwatchedTitle = Title::factory()->show()->create(['name' => 'Not Watched Yet']);
    $unwatchedSeason = Season::factory()->for($unwatchedTitle)->create(['season_number' => 1]);
    Episode::factory()->for($unwatchedSeason)->create([
        'title_id' => $unwatchedTitle->id,
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => Carbon::today()->addDays(2),
    ]);
    Follow::factory()->for($unwatchedTitle)->create();

    $titleIds = Livewire::test('pages::dashboard')->instance()->airingThisWeek->pluck('title_id');

    expect($titleIds)->not->toContain($watchedTitle->id)
        ->and($titleIds)->toContain($unwatchedTitle->id);
});

test('marking an airing episode watched removes it from the Airing This Week shelf', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create(['name' => 'Mark Me Watched']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => Carbon::today(),
    ]);
    Follow::factory()->for($title)->create();

    $component = Livewire::test('pages::dashboard');

    expect($component->instance()->airingThisWeek->pluck('title_id'))->toContain($title->id);

    $component->call('markWatched', $episode->id);

    expect($component->instance()->airingThisWeek->pluck('title_id'))->not->toContain($title->id);
});

test('marking the continue-watching episode of a restarted show logs a new play even though it was already watched before the restart', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'Rewatch Me']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::withoutEvents(fn () => Play::factory()->for($episode, 'playable')->create(['watched_at' => now()->subYear()]));
    Follow::factory()->for($title)->completed()->create();

    app(RestartShow::class)->handle($title);

    Livewire::test('pages::dashboard')->call('markWatched', $episode->id);

    expect($episode->plays()->count())->toBe(2);
});

test('airing this week page renders correctly when a paused show has an episode airing soon', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create(['name' => 'On Hold Airing']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'air_date' => Carbon::today()->addDays(2),
    ]);
    Follow::factory()->paused()->for($title)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
});

function mockCacheForRecentlyAdded(mixed $test, array $entries): void
{
    $mock = Mockery::mock(UpNextCache::class);
    $mock->shouldReceive('continueWatching')->andReturn(collect());
    $mock->shouldReceive('airingThisWeek')->andReturn(collect());
    $mock->shouldReceive('airingThisWeekByDay')->andReturn(collect());
    $mock->shouldReceive('plexConfigured')->andReturn(true);
    $mock->shouldReceive('recentlyAdded')->andReturn(collect($entries));

    app()->instance(UpNextCache::class, $mock);
}

test('recently added to plex shows the section heading, subheading, and entries in order', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['name' => 'Fresh Movie']);
    $show = Title::factory()->show()->create(['name' => 'Fresh Show']);

    mockCacheForRecentlyAdded($this, [
        ['title' => $show, 'added_at' => CarbonImmutable::now()->subDay(), 'episode_count' => 2, 'season_number' => null, 'episode_number' => null, 'label' => '2 new episodes'],
        ['title' => $movie, 'added_at' => CarbonImmutable::now()->subDays(5), 'episode_count' => 0, 'season_number' => null, 'episode_number' => null, 'label' => null],
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(__('Recently Added to Plex'));
    $response->assertSee('New on your Plex server in the last two weeks');
    $response->assertSeeInOrder(['Fresh Show', 'Fresh Movie']);
});

test('the recently added to plex section is hidden when there are no recently added items', function () {
    $this->actingAs(User::factory()->create());

    mockCacheForRecentlyAdded($this, []);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(__('Recently Added to Plex'))
        ->assertSee(__('Nothing here yet'));
});

test('recently added to plex shows a label badge when non-null', function () {
    $this->actingAs(User::factory()->create());

    $show = Title::factory()->show()->create(['name' => 'Badge Show']);

    mockCacheForRecentlyAdded($this, [
        ['title' => $show, 'added_at' => CarbonImmutable::now()->subDay(), 'episode_count' => 3, 'season_number' => null, 'episode_number' => null, 'label' => '3 new episodes'],
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('3 new episodes');
});

test('recently added to plex does not show a label badge for movies', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['name' => 'No Badge Movie']);

    mockCacheForRecentlyAdded($this, [
        ['title' => $movie, 'added_at' => CarbonImmutable::now()->subDays(3), 'episode_count' => 0, 'season_number' => null, 'episode_number' => null, 'label' => null],
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('No Badge Movie');
    $response->assertDontSee('new episodes');
});

test('recently added to plex cards leave out the redundant plex badge', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['name' => 'Plex Listed Movie']);

    mockCacheForRecentlyAdded($this, [
        ['title' => $movie, 'added_at' => CarbonImmutable::now()->subDay(), 'episode_count' => 0, 'season_number' => null, 'episode_number' => null, 'label' => null],
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertDontSee(__('Available on Plex'), false);
});

test('recently added to plex shows the episode label in the bottom-right corner', function () {
    $this->actingAs(User::factory()->create());

    $show = Title::factory()->show()->create(['name' => 'Corner Badge Show']);

    mockCacheForRecentlyAdded($this, [
        ['title' => $show, 'added_at' => CarbonImmutable::now()->subDay(), 'episode_count' => 3, 'season_number' => null, 'episode_number' => null, 'label' => '3 new episodes'],
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder(['absolute bottom-1.5 right-1.5', '3 new episodes'], false);
});

test('recently added to plex each poster links to the title page', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['name' => 'Linked Movie']);

    mockCacheForRecentlyAdded($this, [
        ['title' => $movie, 'added_at' => CarbonImmutable::now()->subDay(), 'episode_count' => 0, 'season_number' => null, 'episode_number' => null, 'label' => null],
    ]);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(route('titles.show', $movie), false);
});

test('a paused show appears on no Up Next shelf', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'On Hold']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Follow::factory()->paused()->for($title)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertDontSee('On Hold');
});

test('the empty state still shows correctly when only paused shows exist', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'On Hold']);
    Follow::factory()->paused()->for($title)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(__('Nothing here yet'));
});

test('continue watching renders episode cards with a progress bar', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'Card Show']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1, 'name' => 'First Up']);
    Follow::factory()->for($title)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('aspect-video', false);
    $response->assertSee('S01E01', false);
    $response->assertSee('First Up');
    $response->assertSee('left-2 bottom-2', false);
});

test('airing this week renders episode cards', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create(['name' => 'Soon Airing']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 4,
        'name' => 'Coming Soon',
        'air_date' => Carbon::today()->addDays(3),
    ]);
    Follow::factory()->for($title)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('aspect-video', false);
    $response->assertSee('S01E04', false);
    $response->assertSee('Coming Soon');
    $response->assertSee('left-2 bottom-2', false);
});

test('continue watching cards wire the "Watched on…" submenu', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'Menu Wired Show']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Follow::factory()->for($title)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(__('Watched on…'));
    $response->assertSee("x-show=\"!value\" x-on:click=\"set(true, () => \$wire.markWatched({$episode->id}, &#039;release_date&#039;))\"", false);
    $response->assertSee("x-show=\"!value\" x-on:click=\"set(true, () => \$wire.markWatched({$episode->id}, &#039;unknown&#039;))\"", false);
    $response->assertSee("x-on:click=\"customDatetimeTarget = &#039;{$episode->id}&#039;; customDatetime = window.nowInDisplayTimezone(document.documentElement.dataset.timezone); \$flux.modal(&#039;custom-watched-at&#039;).show()\"", false);
    $response->assertSee("x-on:watched-custom-flip.window=\"if (\$event.detail.target === '{$episode->id}') { bulkSet(true) }\"", false);
    $response->assertDontSee("wire:click=\"markWatched({$episode->id}, &#039;release_date&#039;)\"", false);
    $response->assertDontSee("wire:click=\"markWatched({$episode->id}, &#039;unknown&#039;)\"", false);
    $response->assertDontSee("wire:click=\"openCustomDatetime({$episode->id})\"", false);
});

test('airing this week cards wire the "Watched on…" submenu', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create(['name' => 'Airing Menu Wired']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => Carbon::today(),
    ]);
    Follow::factory()->for($title)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(__('Watched on…'));
    $response->assertSee("x-show=\"!value\" x-on:click=\"set(true, () => \$wire.markWatched({$episode->id}, &#039;release_date&#039;))\"", false);
    $response->assertSee("x-show=\"!value\" x-on:click=\"set(true, () => \$wire.markWatched({$episode->id}, &#039;unknown&#039;))\"", false);
    $response->assertSee("x-on:click=\"customDatetimeTarget = &#039;{$episode->id}&#039;; customDatetime = window.nowInDisplayTimezone(document.documentElement.dataset.timezone); \$flux.modal(&#039;custom-watched-at&#039;).show()\"", false);
    $response->assertSee("x-on:watched-custom-flip.window=\"if (\$event.detail.target === '{$episode->id}') { bulkSet(true) }\"", false);
    $response->assertDontSee("wire:click=\"markWatched({$episode->id}, &#039;release_date&#039;)\"", false);
    $response->assertDontSee("wire:click=\"markWatched({$episode->id}, &#039;unknown&#039;)\"", false);
    $response->assertDontSee("wire:click=\"openCustomDatetime({$episode->id})\"", false);
});

test('marking an episode watched on its release date logs a play at that date', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'air_date' => Carbon::today()->subDays(10),
    ]);
    Follow::factory()->for($title)->create();

    Livewire::test('pages::dashboard')->call('markWatched', $episode->id, 'release_date');

    expect($episode->plays()->first()->watched_at->toDateString())->toBe($episode->air_date->toDateString());
});

test('marking an episode watched with an unknown date logs a play with no watched_at', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Follow::factory()->for($title)->create();

    Livewire::test('pages::dashboard')->call('markWatched', $episode->id, 'unknown');

    expect($episode->plays()->first()->watched_at)->toBeNull();
});

test('the custom-datetime modal Save closes instantly, flips the matching card, and calls $wire directly', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Follow::factory()->for($title)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('wire:submit="confirmCustomDatetime(customDatetimeTarget, customDatetime)"', false);
    $response->assertSee("\$flux.modal('custom-watched-at').close();", false);
    $response->assertSee("window.dispatchEvent(new CustomEvent('watched-custom-flip', { detail: { target: customDatetimeTarget } }));", false);
    $response->assertSee('$wire.confirmCustomDatetime(customDatetimeTarget, customDatetime).catch', false);
});

test('a custom watched datetime opens a modal then logs the play, interpreted in the user\'s timezone', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Follow::factory()->for($title)->create();

    Livewire::test('pages::dashboard')
        ->call('confirmCustomDatetime', (string) $episode->id, '2015-07-04T09:00');

    // 9am in America/New_York (EDT, UTC-4) on 2015-07-04 is 13:00 UTC.
    expect($episode->plays()->first()->watched_at->format('Y-m-d H:i'))->toBe('2015-07-04 13:00');
});

test('a custom watched datetime removes the episode from the Airing This Week shelf', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'air_date' => Carbon::today(),
    ]);
    Follow::factory()->for($title)->create();

    $component = Livewire::test('pages::dashboard');

    expect($component->instance()->airingThisWeek->pluck('title_id'))->toContain($title->id);

    $component->call('confirmCustomDatetime', (string) $episode->id, '2020-01-01T09:00');

    expect($component->instance()->airingThisWeek->pluck('title_id'))->not->toContain($title->id);
});

test('episode cards on the dashboard link to the current page with ?episode= and the poster links to the show', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create(['name' => 'Linked Show']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Follow::factory()->for($title)->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(route('dashboard', ['episode' => $episode->id]), false);
    $response->assertSee(route('titles.show', $title), false);
});

test('toggling watched from the flyout refreshes the dashboard and drops the episode from Airing This Week', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create(['name' => 'Refresh Me']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => Carbon::today(),
    ]);
    Follow::factory()->for($title)->create();

    $component = Livewire::test('pages::dashboard');

    expect($component->instance()->airingThisWeek->pluck('title_id'))->toContain($title->id);

    Play::factory()->for($episode, 'playable')->create();
    $component->call('refreshEpisodeLists');

    expect($component->instance()->airingThisWeek->pluck('title_id'))->not->toContain($title->id);
});

test('airing this week groups episodes by air day with a separator between groups', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create(['name' => 'Two Day Show']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => Carbon::today()->addDays(1),
    ]);
    Episode::factory()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 2,
        'air_date' => Carbon::today()->addDays(3),
    ]);
    Follow::factory()->for($title)->create();

    $byDay = Livewire::test('pages::dashboard')->instance()->airingThisWeekByDay;

    expect($byDay)->toHaveCount(2);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    expect(substr_count($response->getContent(), 'w-px shrink-0 self-stretch bg-line'))->toBe(1);
});

test('airing this week keeps same-day episodes in one group with no separator', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create(['name' => 'Same Day Show']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => Carbon::today()->addDays(2),
    ]);
    Episode::factory()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 2,
        'air_date' => Carbon::today()->addDays(2),
    ]);
    Follow::factory()->for($title)->create();

    $byDay = Livewire::test('pages::dashboard')->instance()->airingThisWeekByDay;

    expect($byDay)->toHaveCount(1);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    expect(substr_count($response->getContent(), 'w-px shrink-0 self-stretch bg-line'))->toBe(0);
});

test('airing this week uses the user\'s local calendar day, not UTC, to decide what airs today', function () {
    // 2026-01-01 20:00 UTC is still 2026-01-01 in UTC, but already 2026-01-02 in
    // Pacific/Kiritimati (UTC+14).
    Carbon::setTestNow('2026-01-01 20:00:00');

    $this->actingAs(User::factory()->create(['timezone' => 'Pacific/Kiritimati']));

    $title = Title::factory()->show()->create(['name' => 'Dateline Show']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => '2026-01-02',
    ]);
    Follow::factory()->for($title)->create();

    $dashboard = Livewire::test('pages::dashboard');

    expect($dashboard->instance()->airingThisWeek->pluck('title_id'))->toContain($title->id);
    expect($dashboard->instance()->dayLabel(Carbon::parse('2026-01-02')))->toBe('Today');

    Carbon::setTestNow();
});

test('marking a nonexistent episode watched fails server-side and leaves no play logged', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::dashboard')
        ->call('markWatched', 999999)
        ->assertStatus(404);

    expect(Play::query()->count())->toBe(0);
});

test('follow control shows a follow button for an unfollowed show', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();

    Livewire::test('follow-control', ['title' => $title])
        ->assertSee(__('Follow'))
        ->call('follow');

    expect(Follow::query()->where('title_id', $title->id)->first()->state)->toBe(FollowState::Watching);
});

test('follow control pauses a watching show', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->create();

    Livewire::test('follow-control', ['title' => $title])
        ->assertSee(__('Watching'))
        ->call('pause');

    expect($follow->fresh()->state)->toBe(FollowState::Paused);
});

test('follow control resumes a paused or abandoned show', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->abandoned()->for($title)->create();

    Livewire::test('follow-control', ['title' => $title])
        ->assertSee(__('Resume'))
        ->call('resume');

    expect($follow->fresh()->state)->toBe(FollowState::Watching);
});

test('the greeting follows the user\'s local time of day', function (string $utcTime, string $expected) {
    Carbon::setTestNow(Carbon::parse($utcTime, 'UTC'));

    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee($expected);

    Carbon::setTestNow();
})->with([
    'morning' => ['2026-01-01 13:00:00', 'Good morning.'],
    'afternoon' => ['2026-01-01 19:00:00', 'Good afternoon.'],
    'evening' => ['2026-01-01 02:00:00', 'Good evening.'],
    'evening across the UTC/local dateline' => ['2026-06-01 23:30:00', 'Good evening.'],
]);

test('continue watching cards show the Plex marker when the next episode is on Plex', function () {
    $this->actingAs(User::factory()->create());
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    $title = Title::factory()->show()->create(['name' => 'Plex Show']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    $episode->plexItem()->create(['rating_key' => '901', 'machine_identifier' => 'abc123', 'checked_at' => now()]);
    Follow::factory()->for($title)->create();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('Available on Plex'), false)
        ->assertSee(__('Watch on Plex'));
});

test('continue watching cards show no Plex marker when Plex is not configured', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'Plex Show']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    $episode->plexItem()->create(['rating_key' => '901', 'machine_identifier' => 'abc123', 'checked_at' => now()]);
    Follow::factory()->for($title)->create();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(__('Available on Plex'), false);
});

test('airing this week cards never show a Plex marker', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    $title = Title::factory()->show()->create(['name' => 'Soon Airing']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 4,
        'air_date' => Carbon::today()->addDays(3),
    ]);
    $episode->plexItem()->create(['rating_key' => '902', 'machine_identifier' => 'abc123', 'checked_at' => now()]);
    Follow::factory()->for($title)->create();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('S01E04', false)
        ->assertDontSee(__('Available on Plex'), false);
});
