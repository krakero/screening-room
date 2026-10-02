<?php

use App\Actions\Follows\RestartShow;
use App\Enums\PlaySource;
use App\Jobs\ResolveEpisodePlexAvailability;
use App\Livewire\EpisodeFlyout;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function configurePlexForFlyoutTest(): void
{
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
}

test('the still bleeds edge to edge with a fade', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertOk()
        ->assertSee($episode->stillUrl('w780'), escape: false)
        ->assertDontSee('@if');
});

test('only the show poster tile renders, with a season row and an accent-coloured header link', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create(['name' => 'Breaking Bad']);
    $season = Season::factory()->for($title)->create(['season_number' => 2, 'episode_count' => 3]);
    $episodes = Episode::factory()->aired()->for($season)->count(3)->sequence(
        ['episode_number' => 1],
        ['episode_number' => 2],
        ['episode_number' => 3],
    )->create(['title_id' => $title->id, 'season_number' => 2]);
    Play::factory()->for($episodes->first(), 'playable')->create();

    $seasonHref = route('titles.seasons.show', [$title, 2]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episodes->first()->id])
        ->assertOk()
        ->assertSee($seasonHref, escape: false)
        ->assertSee('class="text-accent-content hover:underline">S02E01</a>', escape: false)
        ->assertSee(route('titles.show', $title), escape: false)
        ->assertSee('Season 2')
        ->assertSee(__(':count episodes', ['count' => 3]))
        ->assertSee(__(':watched / :total watched', ['watched' => 1, 'total' => 3]))
        ->assertSee('Breaking Bad')
        ->assertDontSee(__('More from this show'))
        ->assertDontSee('@if');
});

test('the flyout renders on a real page without a "one root element" error', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    // A full HTTP page load exercises the layout-mounted <livewire:episode-flyout />
    // through Livewire's real render/hydrate cycle, which is what enforces "one root
    // HTML element per component" — Livewire::test() alone doesn't reliably catch this.
    $this->get(route('dashboard'))->assertOk();

    $this->get(route('dashboard', ['episode' => $episode->id]))
        ->assertOk()
        ->assertSee($episode->title->name)
        ->assertDontSee('@if');
});

test('an invalid episode id renders nothing and no error', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    Livewire::test(EpisodeFlyout::class, ['episodeId' => 999999])
        ->assertOk()
        ->assertDontSee('@if');
});

test('a valid episode id renders the episode details with no credits section and no Http call', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create(['name' => 'Breaking Bad']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create([
        'title_id' => $title->id,
        'season_number' => 1,
        'episode_number' => 1,
        'name' => 'Pilot',
    ]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertOk()
        ->assertSee('Breaking Bad')
        ->assertSee('S01E01')
        ->assertSee('— Pilot', escape: false)
        ->assertDontSee(__('Crew'))
        ->assertDontSee('@if');

    Http::assertNothingSent();
});

test('an unaired episode has no watched toggle', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->unaired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertOk()
        ->assertSee(__('Unaired'))
        ->assertDontSee(__('Mark watched'));

    Http::assertNothingSent();
});

test('toggling watched logs a play and dispatches the refresh event', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->call('toggleWatched')
        ->assertDispatched('episode-watched-changed', episodeId: $episode->id);

    expect($episode->plays()->count())->toBe(1);
});

test('toggling watched again removes the manual play', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($episode, 'playable')->create(['source' => PlaySource::Manual]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->call('toggleWatched');

    expect($episode->plays()->count())->toBe(0);
});

test('while rewatching, an episode watched only before the restart shows unwatched in the flyout', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($episode, 'playable')->create(['watched_at' => now()->subYear()]);

    app(RestartShow::class)->handle($title);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->call('toggleWatched');

    expect($episode->plays()->count())->toBe(2);
});

test('while rewatching, toggling watched off removes the play logged since the restart, not the older one', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    $oldPlay = Play::factory()->for($episode, 'playable')->create(['watched_at' => now()->subYear()]);

    app(RestartShow::class)->handle($title);
    $newPlay = Play::factory()->for($episode, 'playable')->create(['watched_at' => now()]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->call('toggleWatched');

    expect(Play::query()->whereKey($oldPlay->id)->exists())->toBeTrue()
        ->and(Play::query()->whereKey($newPlay->id)->exists())->toBeFalse();
});

test('while rewatching, the season stats only count plays since the restart', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'episode_count' => 2]);
    $episodes = Episode::factory()->aired()->for($season)->count(2)->sequence(
        ['episode_number' => 1],
        ['episode_number' => 2],
    )->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($episodes->first(), 'playable')->create(['watched_at' => now()->subYear()]);

    app(RestartShow::class)->handle($title);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episodes->first()->id])
        ->assertSee(__(':watched / :total watched', ['watched' => 0, 'total' => 2]));
});

test('the watched toggle flips instantly client-side via the optimistic helper, not a plain wire:click', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertOk()
        ->assertSee('x-data="{ ...optimistic(false), manual: false }"', false)
        ->assertSee('x-on:click="manual = true; set(true, () => $wire.toggleWatched())"', false)
        ->assertDontSee('wire:click="toggleWatched"', false)
        ->assertDontSee('@if');
});

test('the watched toggle marks a manually-logged play removable with no confirm dialog', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($episode, 'playable')->create(['source' => PlaySource::Manual]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertOk()
        ->assertSee('x-data="{ ...optimistic(true), manual: true }"', false)
        ->assertSee('x-on:click="set(false, () => $wire.toggleWatched())"', false);
});

test('the watched toggle asks to confirm before removing a play logged via Plex/Trakt', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($episode, 'playable')->create(['source' => PlaySource::Plex]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertOk()
        ->assertSee('x-data="{ ...optimistic(true), manual: false }"', false)
        ->assertSee('confirm(', false);
});

test('the "Pick date & time…" menu item opens the picker client-side, with no wire:click', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertSee('x-on:click="customDatetime = window.nowInDisplayTimezone(document.documentElement.dataset.timezone); $flux.modal(&#039;episode-flyout-custom-watched-at&#039;).show()"', false)
        ->assertDontSee('wire:click="openCustomDatetime"', false)
        ->assertSee('data-modal="episode-flyout-custom-watched-at"', false);
});

test('the pick-datetime modal Save closes instantly, flips the watched button, and calls $wire directly', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertDontSee('wire:submit="confirmCustomDatetime(customDatetime)"', false)
        ->assertSee("\$flux.modal('episode-flyout-custom-watched-at').close();", false)
        ->assertSee("window.dispatchEvent(new CustomEvent('episode-flyout-datetime-picked'));", false)
        ->assertSee('$wire.confirmCustomDatetime(customDatetime).catch', false)
        ->assertSee('x-on:episode-flyout-datetime-picked.window="manual = true; bulkSet(true)"', false)
        ->assertSee('x-on:episode-flyout-datetime-failed.window="bulkSet(false)"', false);
});

test('confirming a custom watched datetime logs the play, interpreted in the user\'s timezone', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->call('confirmCustomDatetime', '2015-07-04T09:00')
        ->assertDispatched('episode-watched-changed', episodeId: $episode->id);

    // 9am in America/New_York (EDT, UTC-4) on 2015-07-04 is 13:00 UTC.
    expect($episode->plays()->first()->watched_at->format('Y-m-d H:i'))->toBe('2015-07-04 13:00');
});

test('previous and next navigate between the show episodes in order', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $first = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    $second = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 2]);
    $third = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 3]);

    $component = Livewire::test(EpisodeFlyout::class, ['episodeId' => $second->id]);

    expect($component->instance()->previousEpisode->id)->toBe($first->id)
        ->and($component->instance()->nextEpisode->id)->toBe($third->id);

    $component->call('goTo', $third->id);

    expect($component->get('episodeId'))->toBe($third->id)
        ->and($component->instance()->nextEpisode)->toBeNull();
});

test('an open-episode event from a card opens the flyout without a page reload', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->unaired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test(EpisodeFlyout::class)
        ->dispatch('open-episode', episodeId: $episode->id)
        ->assertSet('episodeId', $episode->id)
        ->assertDispatched('modal-show', name: 'episode-flyout');
});

// --- Plex: cached-only, deferred + pending/poll (PERF-1) ---

test('an aired episode with no cached Plex row shows a pending state and queues a resolve job, with no Http call', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();
    Queue::fake();
    configurePlexForFlyoutTest();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $component = Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertOk()
        ->assertSee(__('Checking Plex…'))
        ->assertSet('awaitingPlexDispatchedAt', fn (?int $value) => $value !== null);

    $checkingPos = strpos($component->html(), __('Checking Plex…'));
    expect($checkingPos)->not->toBeFalse();
    expect(substr($component->html(), max(0, $checkingPos - 800), 1600))->toContain('animate-spin');

    Queue::assertPushed(ResolveEpisodePlexAvailability::class, fn (ResolveEpisodePlexAvailability $job): bool => $job->episode->is($episode));
    Http::assertNothingSent();
});

test('a stale cached Plex row is used as-is: no pending state, no queued job, no Http call', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();
    Queue::fake();
    configurePlexForFlyoutTest();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    $episode->plexItem()->create(['rating_key' => '901', 'machine_identifier' => 'abc123def456', 'checked_at' => now()->subDays(30)]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertOk()
        ->assertSet('plexUrl', 'https://app.plex.tv/desktop/#!/server/abc123def456/details?key=%2Flibrary%2Fmetadata%2F901')
        ->assertSet('awaitingPlexDispatchedAt', null)
        ->assertDontSee(__('Checking Plex…'))
        ->assertSee(__('Watch on Plex'));

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('checkPlex clears the pending state and shows the button once the row exists', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();
    Queue::fake();
    configurePlexForFlyoutTest();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $component = Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertSet('awaitingPlexDispatchedAt', fn (?int $value) => $value !== null);

    // The queued job runs in the background and stores the row.
    $episode->plexItem()->create(['rating_key' => '901', 'machine_identifier' => 'abc123def456', 'checked_at' => now()]);

    $component->call('checkPlex')
        ->assertSet('awaitingPlexDispatchedAt', null)
        ->assertSet('plexUrl', 'https://app.plex.tv/desktop/#!/server/abc123def456/details?key=%2Flibrary%2Fmetadata%2F901')
        ->assertDontSee(__('Checking Plex…'));
});

test('checkPlex stops polling after ~30s even if nothing resolved', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();
    Queue::fake();
    configurePlexForFlyoutTest();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $component = Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertSet('awaitingPlexDispatchedAt', fn (?int $value) => $value !== null)
        ->set('awaitingPlexDispatchedAt', now()->subSeconds(31)->timestamp);

    $component->call('checkPlex')
        ->assertSet('awaitingPlexDispatchedAt', null)
        ->assertSet('plexUrl', null)
        ->assertDontSee(__('Checking Plex…'));
});

test('an unconfigured Plex integration shows no button and no pending state', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();
    Queue::fake();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertSet('plexUrl', null)
        ->assertSet('awaitingPlexDispatchedAt', null)
        ->assertDontSee(__('Checking Plex…'))
        ->assertDontSee(__('Watch on Plex'));

    Queue::assertNothingPushed();
});

test('goTo re-resolves Plex for the newly loaded episode', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();
    Queue::fake();
    configurePlexForFlyoutTest();

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $first = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    $second = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 2]);
    $second->plexItem()->create(['rating_key' => '902', 'machine_identifier' => 'abc123def456', 'checked_at' => now()]);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $first->id])
        ->assertSet('awaitingPlexDispatchedAt', fn (?int $value) => $value !== null)
        ->call('goTo', $second->id)
        ->assertSet('awaitingPlexDispatchedAt', null)
        ->assertSet('plexUrl', 'https://app.plex.tv/desktop/#!/server/abc123def456/details?key=%2Flibrary%2Fmetadata%2F902');

    Queue::assertPushed(ResolveEpisodePlexAvailability::class, fn (ResolveEpisodePlexAvailability $job): bool => $job->episode->is($first));
});
