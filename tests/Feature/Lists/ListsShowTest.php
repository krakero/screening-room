<?php

use App\Models\Episode;
use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Models\Network;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Models\WatchProvider;
use App\Support\IntegrationSettings;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $mediaList = MediaList::factory()->create();

    $response = $this->get(route('lists.show', $mediaList));

    $response->assertRedirect(route('login'));
});

test('an empty list shows an empty state', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();

    $response = $this->get(route('lists.show', $mediaList));

    $response->assertOk();
    $response->assertSee(__('No titles yet'));
});

test('a list shows its titles in position order', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    $first = Title::factory()->create(['name' => 'First Title']);
    $second = Title::factory()->create(['name' => 'Second Title']);

    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $second->id, 'position' => 1]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $first->id, 'position' => 0]);

    $items = Livewire::test('pages::lists.show', ['mediaList' => $mediaList])->instance()->items;

    expect($items->pluck('title_id')->all())->toBe([$first->id, $second->id]);
});

test('each poster links to its title detail page', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    $title = Title::factory()->create();
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id]);

    $response = $this->get(route('lists.show', $mediaList));

    $response->assertOk();
    $response->assertSee(route('titles.show', $title), escape: false);
});

test('an item can be removed from a list', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    $title = Title::factory()->create();
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id]);

    Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->call('removeItem', $title->id);

    expect($mediaList->items()->where('title_id', $title->id)->exists())->toBeFalse();
});

test('the status filter only returns shows matching the selected status', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();

    $ongoing = Title::factory()->show()->create(['name' => 'Ongoing Show', 'status' => 'Returning Series']);
    $ended = Title::factory()->show()->create(['name' => 'Ended Show', 'status' => 'Ended']);
    $movie = Title::factory()->movie()->create(['name' => 'A Movie']);

    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $ongoing->id, 'position' => 0]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $ended->id, 'position' => 1]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $movie->id, 'position' => 2]);

    $items = Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->set('statusFilter', 'ongoing')
        ->instance()->items;

    expect($items->pluck('title_id')->all())->toBe([$ongoing->id]);
});

test('each show status filter value returns only titles with that status', function (string $filter, string $expectedTmdbStatus) {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();

    $matching = Title::factory()->show()->create(['status' => $expectedTmdbStatus]);
    $other = Title::factory()->show()->create(['status' => $expectedTmdbStatus === 'Returning Series' ? 'Ended' : 'Returning Series']);

    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $matching->id]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $other->id]);

    $items = Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->set('statusFilter', $filter)
        ->instance()->items;

    expect($items->pluck('title_id')->all())->toBe([$matching->id]);
})->with([
    ['ongoing', 'Returning Series'],
    ['upcoming', 'Planned'],
    ['ended', 'Ended'],
    ['canceled', 'Canceled'],
]);

test('the status filter defaults to showing every title', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    $ongoing = Title::factory()->show()->create(['status' => 'Returning Series']);
    $movie = Title::factory()->movie()->create();

    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $ongoing->id]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $movie->id]);

    $items = Livewire::test('pages::lists.show', ['mediaList' => $mediaList])->instance()->items;

    expect($items->pluck('title_id')->all())->toContain($ongoing->id, $movie->id);
});

test('the status filter is reflected in the URL query string', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();

    Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->set('statusFilter', 'ended')
        ->assertSet('statusFilter', 'ended');
});

test('the list page renders without a filter selected', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    $title = Title::factory()->show()->create(['status' => 'Ended']);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id]);

    $response = $this->get(route('lists.show', $mediaList));

    $response->assertOk();
    $response->assertDontSee('@if', false);
    $response->assertSee(__('All statuses'));
});

test('items can be reordered by drag and drop', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    $first = Title::factory()->create();
    $second = Title::factory()->create();
    $third = Title::factory()->create();

    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $first->id, 'position' => 0]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $second->id, 'position' => 1]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $third->id, 'position' => 2]);

    // Move the third title to the first position.
    Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->call('handleSort', $third->id, 0);

    $order = $mediaList->items()->orderBy('position')->pluck('title_id')->all();

    expect($order)->toBe([$third->id, $first->id, $second->id]);
});

function listWithTitles(MediaList $mediaList, array $titles): void
{
    foreach (array_values($titles) as $position => $title) {
        MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id, 'position' => $position]);
    }
}

function listPageItemNames(MediaList $mediaList, array $state): array
{
    return Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->set($state)
        ->instance()->items->pluck('title.name')->all();
}

test('each sort orders the list correctly', function (string $sort, array $expected) {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-06-15');

    $mediaList = MediaList::factory()->create();

    $zebra = Title::factory()->movie()->create(['name' => 'Zebra', 'release_date' => '2020-01-01']);
    $apple = Title::factory()->movie()->create(['name' => 'apple', 'release_date' => '2024-01-01']);
    $mango = Title::factory()->movie()->create(['name' => 'Mango', 'release_date' => null]);
    $soon = Title::factory()->movie()->create(['name' => 'Soon', 'release_date' => '2026-07-01']);
    $later = Title::factory()->movie()->create(['name' => 'Later', 'release_date' => '2027-01-01']);

    // Manual order: Zebra, apple, Mango, Soon, Later.
    listWithTitles($mediaList, [$zebra, $apple, $mango, $soon, $later]);

    Rating::factory()->create(['rateable_id' => $zebra->id, 'score' => 6]);
    Rating::factory()->create(['rateable_id' => $later->id, 'score' => 9]);

    // Added-at order is the reverse of insertion: Later was added last.
    foreach ([$zebra, $apple, $mango, $soon, $later] as $index => $title) {
        MediaListItem::where('title_id', $title->id)->update(['created_at' => now()->addMinutes($index)]);
    }

    expect(listPageItemNames($mediaList, ['sort' => $sort]))->toBe($expected);
})->with([
    'manual' => ['manual', ['Zebra', 'apple', 'Mango', 'Soon', 'Later']],
    'recently released' => ['recently-released', ['Later', 'Soon', 'apple', 'Zebra', 'Mango']],
    'oldest release' => ['oldest-release', ['Zebra', 'apple', 'Soon', 'Later', 'Mango']],
    'upcoming first' => ['upcoming-first', ['Soon', 'Later', 'apple', 'Zebra', 'Mango']],
    'recently added' => ['recently-added', ['Later', 'Soon', 'Mango', 'apple', 'Zebra']],
    'title' => ['title', ['apple', 'Later', 'Mango', 'Soon', 'Zebra']],
    'my rating' => ['my-rating', ['Later', 'Zebra', 'apple', 'Mango', 'Soon']],
]);

test('the type filter only returns movies or shows', function (string $type, string $expected) {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    listWithTitles($mediaList, [
        Title::factory()->movie()->create(['name' => 'A Movie']),
        Title::factory()->show()->create(['name' => 'A Show']),
    ]);

    expect(listPageItemNames($mediaList, ['type' => $type]))->toBe([$expected]);
})->with([
    ['movie', 'A Movie'],
    ['show', 'A Show'],
]);

test('the release filter splits released and upcoming titles', function (string $release, string $expected) {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));
    $this->travelTo('2026-06-15');

    $mediaList = MediaList::factory()->create();
    listWithTitles($mediaList, [
        Title::factory()->movie()->create(['name' => 'Out Now', 'release_date' => '2026-06-15']),
        Title::factory()->movie()->create(['name' => 'Coming', 'release_date' => '2026-06-16']),
    ]);

    expect(listPageItemNames($mediaList, ['release' => $release]))->toBe([$expected]);
})->with([
    ['released', 'Out Now'],
    ['upcoming', 'Coming'],
]);

test('the unwatched filter hides watched movies and fully watched shows', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();

    $watchedMovie = Title::factory()->movie()->create(['name' => 'Watched Movie']);
    $unwatchedMovie = Title::factory()->movie()->create(['name' => 'Unwatched Movie']);
    Play::factory()->create(['playable_id' => $watchedMovie->id]);

    $finishedShow = Title::factory()->show()->create(['name' => 'Finished Show', 'in_production' => false]);
    $partialShow = Title::factory()->show()->create(['name' => 'Partial Show', 'in_production' => false]);
    $untouchedShow = Title::factory()->show()->create(['name' => 'Untouched Show', 'in_production' => false]);

    foreach ([$finishedShow, $partialShow, $untouchedShow] as $show) {
        $season = Season::factory()->for($show)->create(['season_number' => 1]);
        $watchedEpisode = Episode::factory()->aired()->for($season)->create();
        $unwatchedEpisode = Episode::factory()->aired()->for($season)->create();

        if ($show->isNot($untouchedShow)) {
            Play::factory()->create(['playable_type' => 'episode', 'playable_id' => $watchedEpisode->id]);
        }

        if ($show->is($finishedShow)) {
            Play::factory()->create(['playable_type' => 'episode', 'playable_id' => $unwatchedEpisode->id]);
        }
    }

    listWithTitles($mediaList, [$watchedMovie, $unwatchedMovie, $finishedShow, $partialShow, $untouchedShow]);

    expect(listPageItemNames($mediaList, ['unwatched' => true]))
        ->toBe(['Unwatched Movie', 'Partial Show', 'Untouched Show']);
});

test('filters combine', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    listWithTitles($mediaList, [
        Title::factory()->show()->create(['name' => 'Ongoing Show', 'status' => 'Returning Series', 'release_date' => '2000-01-01']),
        Title::factory()->show()->create(['name' => 'Ended Show', 'status' => 'Ended', 'release_date' => '2000-01-01']),
        Title::factory()->movie()->create(['name' => 'A Movie', 'release_date' => '2000-01-01']),
    ]);

    expect(listPageItemNames($mediaList, ['type' => 'show', 'statusFilter' => 'ended', 'release' => 'released']))
        ->toBe(['Ended Show']);
});

test('reordering is ignored while sorted or filtered', function (array $state) {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    $first = Title::factory()->movie()->create(['name' => 'First']);
    $second = Title::factory()->movie()->create(['name' => 'Second']);
    listWithTitles($mediaList, [$first, $second]);

    Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->set($state)
        ->call('handleSort', $second->id, 0);

    expect($mediaList->items()->pluck('title_id')->all())->toBe([$first->id, $second->id]);
})->with([
    'sorted' => [['sort' => 'title']],
    'type' => [['type' => 'movie']],
    'status' => [['statusFilter' => 'ended']],
    'unwatched' => [['unwatched' => true]],
    'release' => [['release' => 'released']],
    'service' => [['service' => '8']],
    'network' => [['network' => '8']],
]);

test('invalid sort and filter values fall back to the defaults', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    listWithTitles($mediaList, [
        Title::factory()->movie()->create(['name' => 'Zebra']),
        Title::factory()->show()->create(['name' => 'Apple']),
    ]);

    $component = Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->set(['sort' => 'bogus', 'type' => 'bogus', 'statusFilter' => 'bogus', 'release' => 'bogus']);

    expect($component->instance()->items->pluck('title.name')->all())->toBe(['Zebra', 'Apple']);
    expect($component->instance()->canReorder)->toBeTrue();
});

test('the empty state explains when filters hide every title', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    listWithTitles($mediaList, [Title::factory()->movie()->create()]);

    Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->set('type', 'show')
        ->assertSee(__('No titles match these filters'));
});

test('the drag handle is only rendered in manual unfiltered order', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    listWithTitles($mediaList, [Title::factory()->movie()->create()]);

    Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->assertSeeHtml('wire:sort:handle')
        ->set('sort', 'title')
        ->assertDontSeeHtml('wire:sort:handle');
});

function watchProvider(Title $title, WatchProvider $provider, string $region = 'US'): void
{
    $title->watchProviders()->attach($provider->id, ['type' => 'flatrate', 'region' => $region]);
}

test('a list can be filtered to titles on a streaming service in the current region', function () {
    $this->actingAs(User::factory()->create());
    config(['services.tmdb.region' => 'US']);

    $mediaList = MediaList::factory()->create();
    $onNetflix = Title::factory()->movie()->create(['name' => 'On Netflix']);
    $onHulu = Title::factory()->movie()->create(['name' => 'On Hulu']);
    $netflixElsewhere = Title::factory()->movie()->create(['name' => 'Netflix In Canada']);
    listWithTitles($mediaList, [$onNetflix, $onHulu, $netflixElsewhere]);
    $netflix = WatchProvider::factory()->create(['name' => 'Netflix']);
    $hulu = WatchProvider::factory()->create(['name' => 'Hulu']);
    watchProvider($onNetflix, $netflix);
    watchProvider($onHulu, $hulu);
    watchProvider($netflixElsewhere, $netflix, 'CA');

    expect(listPageItemNames($mediaList, ['service' => (string) $netflix->id]))->toBe(['On Netflix']);
});

test('the service options are the current region providers of the list titles', function () {
    $this->actingAs(User::factory()->create());
    config(['services.tmdb.region' => 'US']);

    $mediaList = MediaList::factory()->create();
    $inList = Title::factory()->movie()->create();
    $alsoInList = Title::factory()->movie()->create();
    $notInList = Title::factory()->movie()->create();
    listWithTitles($mediaList, [$inList, $alsoInList]);
    $hulu = WatchProvider::factory()->create(['name' => 'Hulu', 'display_priority' => 5]);
    $netflix = WatchProvider::factory()->create(['name' => 'Netflix', 'display_priority' => 1]);
    $disney = WatchProvider::factory()->create(['name' => 'Disney Plus']);
    $apple = WatchProvider::factory()->create(['name' => 'Apple TV+']);
    watchProvider($inList, $hulu);
    watchProvider($alsoInList, $hulu);
    watchProvider($inList, $netflix);
    watchProvider($inList, $disney, 'CA');
    watchProvider($notInList, $apple);

    $options = Livewire::test('pages::lists.show', ['mediaList' => $mediaList])->instance()->serviceOptions;

    expect($options->pluck('name')->all())->toBe(['Netflix', 'Hulu']);
});

test('a list can be filtered to titles on a network', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    $onHbo = Title::factory()->show()->create(['name' => 'On HBO']);
    $onAmc = Title::factory()->show()->create(['name' => 'On AMC']);
    listWithTitles($mediaList, [$onHbo, $onAmc]);
    $hbo = Network::factory()->create(['name' => 'HBO']);
    $amc = Network::factory()->create(['name' => 'AMC']);
    $onHbo->networks()->attach($hbo->id, ['position' => 0]);
    $onAmc->networks()->attach($amc->id, ['position' => 0]);

    expect(listPageItemNames($mediaList, ['network' => (string) $hbo->id]))->toBe(['On HBO']);
});

test('the network options are the networks of the list titles', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();
    $inList = Title::factory()->show()->create();
    $notInList = Title::factory()->show()->create();
    listWithTitles($mediaList, [$inList]);
    $hbo = Network::factory()->create(['name' => 'HBO']);
    $amc = Network::factory()->create(['name' => 'AMC']);
    $fx = Network::factory()->create(['name' => 'FX']);
    $inList->networks()->attach([$hbo->id => ['position' => 0], $amc->id => ['position' => 1]]);
    $notInList->networks()->attach($fx->id, ['position' => 0]);

    $options = Livewire::test('pages::lists.show', ['mediaList' => $mediaList])->instance()->networkOptions;

    expect($options->pluck('name')->all())->toBe(['AMC', 'HBO']);
});

test('with show where to watch off the service filter is hidden and the service param is ignored', function () {
    $this->actingAs(User::factory()->create());
    config(['services.tmdb.region' => 'US']);
    app(IntegrationSettings::class)->set('tmdb.show_watch_providers', false);

    $mediaList = MediaList::factory()->create();
    $onNetflix = Title::factory()->movie()->create(['name' => 'On Netflix']);
    $onHulu = Title::factory()->movie()->create(['name' => 'On Hulu']);
    listWithTitles($mediaList, [$onNetflix, $onHulu]);
    $netflix = WatchProvider::factory()->create(['name' => 'Netflix']);
    watchProvider($onNetflix, $netflix);

    expect(listPageItemNames($mediaList, ['service' => (string) $netflix->id]))->toBe(['On Netflix', 'On Hulu']);

    Livewire::test('pages::lists.show', ['mediaList' => $mediaList])
        ->assertDontSee('Any service');
});
