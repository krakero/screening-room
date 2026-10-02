<?php

use App\Livewire\TitleLists;
use App\Livewire\WatchlistToggle;
use App\Models\MediaList;
use App\Models\Title;
use App\Models\User;
use App\Services\Watch\WatchCacheVersion;
use Livewire\Livewire;

test('the watchlist toggle renders on movie and show pages', function (Title $title) {
    $this->actingAs(User::factory()->create());

    $this->get(route('titles.show', $title))
        ->assertOk()
        ->assertSeeLivewire(WatchlistToggle::class);
})->with([
    'movie' => fn () => Title::factory()->movie()->create(),
    'show' => fn () => Title::factory()->show()->create(),
]);

test('it reflects whether the title is on the watchlist', function () {
    $this->actingAs(User::factory()->create());
    $title = Title::factory()->movie()->create();

    Livewire::test(WatchlistToggle::class, ['title' => $title])
        ->assertSet('onWatchlist', false)
        ->assertSee('Watchlist');

    $title->mediaLists()->attach(MediaList::watchlist(), ['position' => 1]);

    Livewire::test(WatchlistToggle::class, ['title' => $title])
        ->assertSet('onWatchlist', true);
});

test('toggling adds and removes a title from the watchlist', function (Title $title) {
    $this->actingAs(User::factory()->create());

    Livewire::test(WatchlistToggle::class, ['title' => $title])
        ->call('toggle')
        ->assertSet('onWatchlist', true)
        ->assertDispatchedTo(TitleLists::class, 'watchlist-changed', titleId: $title->id)
        ->call('toggle')
        ->assertSet('onWatchlist', false);

    expect(MediaList::watchlist()->titles()->whereKey($title->id)->exists())->toBeFalse();
})->with([
    'movie' => fn () => Title::factory()->movie()->create(),
    'show' => fn () => Title::factory()->show()->create(),
]);

test('a newly added title is appended at the end of the watchlist', function () {
    $this->actingAs(User::factory()->create());
    $watchlist = MediaList::watchlist();
    $existing = Title::factory()->movie()->create();
    $existing->mediaLists()->attach($watchlist, ['position' => 5]);
    $title = Title::factory()->movie()->create();

    Livewire::test(WatchlistToggle::class, ['title' => $title])->call('toggle');

    expect($title->mediaLists()->first()->pivot->position)->toBe(6);
});

test('a guest cannot toggle the watchlist', function () {
    $title = Title::factory()->movie()->create();

    Livewire::test(WatchlistToggle::class, ['title' => $title])
        ->call('toggle')
        ->assertForbidden();

    expect(MediaList::watchlist()->titles()->count())->toBe(0);
});

test('the menu and the toggle stay in sync', function () {
    $this->actingAs(User::factory()->create());
    $title = Title::factory()->movie()->create();

    Livewire::test(TitleLists::class, ['title' => $title])
        ->call('toggleWatchlist')
        ->assertDispatchedTo(WatchlistToggle::class, 'watchlist-changed', titleId: $title->id);

    Livewire::test(WatchlistToggle::class, ['title' => $title])
        ->assertSet('onWatchlist', true);
});

test('toggling refreshes Up Next and timestamps the watchlist entry', function () {
    $this->actingAs(User::factory()->create());
    $title = Title::factory()->movie()->create();
    $version = app(WatchCacheVersion::class);
    $before = $version->current();

    Livewire::test(WatchlistToggle::class, ['title' => $title])->call('toggle');

    expect($version->current())->toBeGreaterThan($before)
        ->and(MediaList::watchlist()->items()->where('title_id', $title->id)->value('created_at'))->not->toBeNull();

    $afterAdd = $version->current();

    Livewire::test(WatchlistToggle::class, ['title' => $title])->call('toggle');

    expect($version->current())->toBeGreaterThan($afterAdd);
});
