<?php

use App\Actions\Plays\LogPlay;
use App\Enums\FollowState;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('calendar'))->assertRedirect(route('login'));
});

test('an empty calendar shows an empty state', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $this->get(route('calendar'))
        ->assertOk()
        ->assertSee(__('Nothing coming up'));
});

test('agenda groups episodes of followed shows by day with today/tomorrow labels', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create(['name' => 'Severance']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'name' => 'Hello, Ms. Cobel',
        'air_date' => Carbon::today(),
    ]);

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 2,
        'name' => 'Half Loop',
        'air_date' => Carbon::tomorrow(),
    ]);

    $response = $this->get(route('calendar'));

    $response->assertOk();
    $response->assertSee(__('Today'));
    $response->assertSee(__('Tomorrow'));
    $response->assertSeeInOrder(['Hello, Ms. Cobel', 'Half Loop']);
});

test('an upcoming episode renders as a 16:9 outlined card with truncated text and no overflow', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create(['name' => 'Severance']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 2,
        'name' => 'Half Loop',
        'air_date' => Carbon::today(),
        'still_path' => '/half-loop.jpg',
    ]);

    $response = $this->get(route('calendar'));

    $response->assertOk();
    $response->assertDontSee('@if', false);
    $response->assertSee($episode->stillUrl('w300'), false);
    $response->assertSee('aspect-video', false);
    $response->assertSee('ring-line', false);
    $response->assertSee('truncate', false);
    $response->assertSee('line-clamp-2', false);
    $response->assertSee('title="S01E02 · Half Loop"', false);
    $response->assertSee('title="Severance"', false);
    $response->assertSee('bg-gradient-to-t', false);
    $response->assertSee(__('Mark watched'));
    $response->assertSee('x-on:click="set(true, () => $wire.markWatched('.$episode->id.'))"', false);
});

test('calendar episode cards show the show poster overlapping the still', function () {
    config(['services.tmdb.image_base_url' => 'https://image.tmdb.org/t/p']);
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create(['name' => 'Severance', 'poster_path' => '/severance-poster.jpg']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'name' => 'Half Loop',
        'air_date' => Carbon::today(),
    ]);

    $response = $this->get(route('calendar'));

    $response->assertOk();
    $response->assertDontSee('@if', false);
    $response->assertSee('https://image.tmdb.org/t/p/w185/severance-poster.jpg', false);
});

test('calendar episode cards link to the current page with ?episode= and the poster links to the show', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create(['name' => 'Severance']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'name' => 'Half Loop',
        'air_date' => Carbon::today(),
    ]);

    $response = $this->get(route('calendar'));

    $response->assertOk();
    $response->assertSee('?episode='.$episode->id, false);
    $response->assertSee(route('titles.show', $show), false);
});

test('the last 7 days catch up section lists unwatched aired episodes as cards below the upcoming section', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create(['name' => 'Severance']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'name' => 'Upcoming Episode',
        'air_date' => Carbon::today(),
    ]);

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 2,
        'name' => 'Catch Up Episode',
        'air_date' => Carbon::today()->subDays(2),
    ]);

    $response = $this->get(route('calendar'));

    $response->assertOk();
    $response->assertDontSee('@if', false);
    $response->assertSee('Upcoming Episode');
    $response->assertSee('Catch Up Episode');
    $response->assertSeeInOrder([__('Today'), __('Last 7 days')]);
    $response->assertSee('S01E02');
});

test('paused shows are hidden from both the upcoming and last 7 days sections', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $activeShow = Title::factory()->show()->create(['name' => 'Severance']);
    $activeSeason = Season::factory()->for($activeShow)->create(['season_number' => 1]);
    Follow::factory()->for($activeShow)->create(['state' => FollowState::Watching]);

    Episode::factory()->for($activeSeason)->create([
        'title_id' => $activeShow->id,
        'episode_number' => 1,
        'name' => 'Active Upcoming Episode',
        'air_date' => Carbon::today(),
    ]);

    Episode::factory()->for($activeSeason)->create([
        'title_id' => $activeShow->id,
        'episode_number' => 2,
        'name' => 'Active Catch Up Episode',
        'air_date' => Carbon::today()->subDays(2),
    ]);

    $pausedShow = Title::factory()->show()->create(['name' => 'Paused Show']);
    $pausedSeason = Season::factory()->for($pausedShow)->create(['season_number' => 1]);
    Follow::factory()->for($pausedShow)->create(['state' => FollowState::Paused]);

    Episode::factory()->for($pausedSeason)->create([
        'title_id' => $pausedShow->id,
        'episode_number' => 1,
        'name' => 'Paused Upcoming Episode',
        'air_date' => Carbon::today(),
    ]);

    Episode::factory()->for($pausedSeason)->create([
        'title_id' => $pausedShow->id,
        'episode_number' => 2,
        'name' => 'Paused Catch Up Episode',
        'air_date' => Carbon::today()->subDays(2),
    ]);

    $response = $this->get(route('calendar'));

    $response->assertOk();
    $response->assertSee('Active Upcoming Episode');
    $response->assertSee('Active Catch Up Episode');
    $response->assertDontSee('Paused Upcoming Episode');
    $response->assertDontSee('Paused Catch Up Episode');
});

test('switching to month view shows a day grid with entry indicators', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->addDays(3),
    ]);

    Livewire::test('pages::calendar')
        ->assertSet('view', 'agenda')
        ->call('switchView', 'month')
        ->assertSet('view', 'month')
        ->assertSee(Carbon::today()->format('F Y'));
});

test('selecting a day in month view filters entries to that day', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'name' => 'Selected Day Episode',
        'air_date' => Carbon::today()->addDays(3),
    ]);

    Livewire::test('pages::calendar')
        ->call('switchView', 'month')
        ->call('selectDay', Carbon::today()->addDays(3)->toDateString())
        ->assertSee('Selected Day Episode');
});

test('a watched episode shows an accent check-mark badge instead of the mark-watched button', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create(['name' => 'Severance']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'name' => 'Half Loop',
        'air_date' => Carbon::today(),
    ]);

    app(LogPlay::class)->handle($episode);

    $response = $this->get(route('calendar'));

    $response->assertOk();
    $response->assertSee('aria-label="Watched"', false);
    $response->assertDontSee('aria-label="Mark watched"', false);
    $response->assertDontSee('wire:click.stop="markWatched('.$episode->id.')"', false);
});

test('marking an aired episode watched from the calendar records a play', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->subDays(2),
    ]);

    Livewire::test('pages::calendar')
        ->call('markWatched', $episode->id);

    expect($episode->plays()->exists())->toBeTrue();
});

test('the episode-watched-changed event refreshes the calendar\'s entry lists', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->subDays(2),
    ]);

    $component = Livewire::test('pages::calendar');

    expect($component->instance()->catchUp->pluck('title.id'))->toContain($show->id);

    app(LogPlay::class)->handle($episode);
    $component->call('refreshEpisodeLists');

    expect($component->instance()->catchUp->pluck('title.id'))->not->toContain($show->id);
});

test('calendar cards wire the "Watched on…" submenu', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create(['name' => 'Menu Wired Show']);
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today(),
    ]);

    $response = $this->get(route('calendar'));

    $response->assertOk();
    $response->assertSee(__('Watched on…'));
    $response->assertSee("x-show=\"!value\" x-on:click=\"set(true, () => \$wire.markWatched({$episode->id}, &#039;release_date&#039;))\"", false);
    $response->assertSee("x-show=\"!value\" x-on:click=\"set(true, () => \$wire.markWatched({$episode->id}, &#039;unknown&#039;))\"", false);
    $response->assertSee("x-on:click=\"customDatetimeTarget = &#039;{$episode->id}&#039;; customDatetime = window.nowInDisplayTimezone(document.documentElement.dataset.timezone); \$flux.modal(&#039;custom-watched-at&#039;).show()\"", false);
    $response->assertSee("x-on:watched-custom-flip.window=\"if (\$event.detail.target === '{$episode->id}') { bulkSet(true) }\"", false);
    $response->assertDontSee("wire:click=\"markWatched({$episode->id}, &#039;release_date&#039;)\"", false);
    $response->assertDontSee("wire:click=\"markWatched({$episode->id}, &#039;unknown&#039;)\"", false);
});

test('marking a calendar episode watched on its release date logs a play at that date', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->subDays(10),
    ]);

    Livewire::test('pages::calendar')->call('markWatched', $episode->id, 'release_date');

    expect($episode->plays()->first()->watched_at->toDateString())->toBe($episode->air_date->toDateString());
});

test('marking a calendar episode watched with an unknown date logs a play with no watched_at', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->subDays(10),
    ]);

    Livewire::test('pages::calendar')->call('markWatched', $episode->id, 'unknown');

    expect($episode->plays()->first()->watched_at)->toBeNull();
});

test('the calendar custom-datetime modal Save closes instantly and calls $wire directly', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today(),
    ]);

    $response = $this->get(route('calendar'));

    $response->assertOk();
    $response->assertDontSee('wire:submit="confirmCustomDatetime(customDatetimeTarget, customDatetime)"', false);
    $response->assertSee("\$flux.modal('custom-watched-at').close();", false);
    $response->assertSee("window.dispatchEvent(new CustomEvent('watched-custom-flip', { detail: { target: customDatetimeTarget } }));", false);
    $response->assertSee('$wire.confirmCustomDatetime(customDatetimeTarget, customDatetime).catch', false);
});

test('a custom watched datetime on the calendar logs the play, interpreted in the user\'s timezone', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->subDays(10),
    ]);

    Livewire::test('pages::calendar')
        ->call('confirmCustomDatetime', (string) $episode->id, '2015-07-04T09:00');

    // 9am in America/New_York (EDT, UTC-4) on 2015-07-04 is 13:00 UTC.
    expect($episode->plays()->first()->watched_at->format('Y-m-d H:i'))->toBe('2015-07-04 13:00');
});

test('a custom watched datetime removes the episode from the calendar\'s catch-up section', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $episode = Episode::factory()->for($season)->create([
        'title_id' => $show->id,
        'episode_number' => 1,
        'air_date' => Carbon::today()->subDays(2),
    ]);

    $component = Livewire::test('pages::calendar');

    expect($component->instance()->catchUp->pluck('title.id'))->toContain($show->id);

    $component->call('confirmCustomDatetime', (string) $episode->id, '2020-01-01T09:00');

    expect($component->instance()->catchUp->pluck('title.id'))->not->toContain($show->id);
});

test('the month grid marks today using the user\'s local calendar day, not UTC', function () {
    // 2026-01-01 20:00 UTC is still 2026-01-01 in UTC, but already 2026-01-02 in
    // Pacific/Kiritimati (UTC+14).
    Carbon::setTestNow('2026-01-01 20:00:00');

    $this->actingAs(User::factory()->create(['timezone' => 'Pacific/Kiritimati']));

    $component = Livewire::test('pages::calendar');

    $today = collect($component->instance()->monthGrid)->firstWhere('isToday', true);

    expect($today['date']->toDateString())->toBe('2026-01-02');

    Carbon::setTestNow();
});
