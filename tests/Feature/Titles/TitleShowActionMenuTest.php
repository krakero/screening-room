<?php

use App\Enums\FollowState;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\MediaList;
use App\Models\Play;
use App\Models\PlexLibraryItem;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * TL-6 built the real Title page to match /title-lab's #1i — title row with
 * Watch on Plex · Trailer · "…" right-aligned, with every other action grouped under the "…".
 * UI-10 flattened that menu per user feedback: no section headings/dividers, no follow-state
 * chip, every row a plain flux:menu.item (Mark watched, Watched on…, a single contextual
 * Pause/Resume/Follow row, Add/Edit review, Add to List). These tests cover that structure
 * specifically; the individual handlers themselves (confirmMarkShowWatched, setScore,
 * openReviewFlyout, Plex/trailer pending states, etc.) already have their own dedicated
 * coverage elsewhere and are unchanged — only their markup position/presentation moved.
 */
test('the title row shows Watch on Plex, then Trailer, then the overflow menu, in that order', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    PlexLibraryItem::factory()->create([
        'plex_rating_key' => '501',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
    ]);

    $title = Title::factory()->movie()->create([
        'name' => 'Fight Club',
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
        'trailer_site' => 'YouTube',
        'trailer_key' => 'abc123',
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSeeInOrder([
        'Fight Club',
        __('Watch on Plex'),
        __('Watch trailer'),
        __('More actions'),
    ]);
});

test('the overflow menu is a flat list with no section headings or dividers, in the expected order', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSeeInOrder([
        __('Mark show watched'),
        __('Watched on…'),
        __('On release date'),
        __('Unknown date'),
        __('Pick date & time…'),
        __('Follow'),
        __('Add review'),
        __('Add to List'),
    ]);
    $response->assertDontSee(__('Watching'));
    $response->assertDontSee(__('Tracking'));
    $response->assertDontSee(__('Yours'));
});

test('the overflow menu has no follow row for a movie, but keeps Mark watched and Add review', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Mark watched'));
    $response->assertSee(__('Add review'));
    $response->assertDontSee(__('Follow'));
    $response->assertDontSee(__('Pause'));
    $response->assertDontSee(__('Resume'));
    $response->assertDontSee(__('Watching'));
    $response->assertDontSee(__('Tracking'));
    $response->assertDontSee(__('Yours'));
});

test('the overflow menu\'s follow row is contextual: Pause when watching, Resume when paused or abandoned', function () {
    // Assert on the row's wire:click target rather than its translated label — the
    // watched-status line legitimately prints "Paused" (the followState label) elsewhere on
    // the page, which is a substring of "Pause" and would make a plain assertDontSee(__('Pause'))
    // a false positive.
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Follow::factory()->for($title)->create(['state' => FollowState::Watching]);

    $response = $this->get(route('titles.show', $title));
    $response->assertOk();
    $response->assertSee('wire:click="pause"', false);
    $response->assertDontSee('wire:click="follow"', false);
    $response->assertDontSee('wire:click="resume"', false);

    $title->follow->update(['state' => FollowState::Paused]);

    $response = $this->get(route('titles.show', $title));
    $response->assertOk();
    $response->assertSee('wire:click="resume"', false);
    $response->assertDontSee('wire:click="follow"', false);
    $response->assertDontSee('wire:click="pause"', false);
});

test('the follow row pauses, resumes and follows a show from the overflow menu', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('follow');

    expect($title->follow()->first()->state)->toBe(FollowState::Watching);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('pause');

    expect($title->follow()->first()->state)->toBe(FollowState::Paused);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('resume');

    expect($title->follow()->first()->state)->toBe(FollowState::Watching);
});

test('the overflow menu shows Edit review once a review exists', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));
    $response->assertOk();
    $response->assertSee(__('Add review'));
    $response->assertDontSee(__('Edit review'));

    Rating::factory()->for($title, 'rateable')->create(['review' => 'Great movie.']);

    $response = $this->get(route('titles.show', $title));
    $response->assertOk();
    $response->assertSee(__('Edit review'));
});

test('the Add to List submenu opens the list picker with the watchlist and custom lists', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    $list = MediaList::factory()->create(['name' => 'Favorites', 'is_watchlist' => false]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Add to List'));
    $response->assertSee(__('Watchlist'));
    $response->assertSee('Favorites');
    $response->assertSee(__('New list'));
});

test('the watched-status label has the follow state and episode count but no SxxEyy next code', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['in_production' => true]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 3]);
    Follow::factory()->for($title)->create(['state' => FollowState::Watching]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Watching').' · '.__(':watched of :total episodes', ['watched' => 0, 'total' => 1]));
    // The old status line included "S03E03 next" (from the up-next episode's SxxEyy code) —
    // that's removed per the user's feedback; the Up next card below is the only place it
    // still shows. Checking for the specific removed episode code (rather than the bare
    // substring "next", which also appears in unrelated Alpine JS like "$nextTick") is what
    // actually pins this down.
    $response->assertDontSee('S03E03 next');
});

test('a movie\'s watched-status label reads "Not watched" until a play exists, then "Watched :date"', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));
    $response->assertOk();
    $response->assertSee(__('Not watched'));

    Play::factory()->for($title, 'playable')->create(['watched_at' => '2026-03-04 12:00:00']);

    $response = $this->get(route('titles.show', $title));
    $response->assertOk();
    $response->assertSee(__('Watched :date', ['date' => 'Mar 4']));
});

test('the star rating is present and renders as an interactive radiogroup', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('role="radiogroup"', false);
    $response->assertSee('wire:key="star-rating-setScore-', false);
});
