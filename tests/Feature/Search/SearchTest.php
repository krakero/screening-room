<?php

use App\Models\Episode;
use App\Models\Follow;
use App\Models\MediaList;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

function searchMultiFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/Fixtures/tmdb/search_multi.json')),
        true,
    );
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('search'));

    $response->assertRedirect(route('login'));
});

test('it points results not yet imported at the passive-import route and never shows an add button', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/search/multi*' => Http::response(searchMultiFixture())]);

    Livewire::test('pages::search')
        ->set('query', 'matrix')
        ->assertSee('The Matrix')
        ->assertSee('Breaking Bad')
        ->assertDontSee('Edward Norton')
        ->assertDontSee(__('Add'))
        ->assertDontSee(__('In library'))
        ->assertSee(route('titles.tmdb', [
            'type' => 'movie',
            'tmdbId' => 603,
            'name' => 'The Matrix',
            'poster' => 'https://image.tmdb.org/t/p/w342/f89U3ADr1oiB1s9GkdPOEpXUk5H.jpg',
            'backdrop' => 'https://image.tmdb.org/t/p/w1280/fNG7i7RqMErkcqhohV2a6cV1Ehy.jpg',
            'year' => '1999',
        ]));
});

test('an already-imported title links straight to its detail page without an in-library badge', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/search/multi*' => Http::response(searchMultiFixture())]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603, 'name' => 'The Matrix']);

    Livewire::test('pages::search')
        ->set('query', 'matrix')
        ->assertDontSee(__('In library'))
        ->assertSee(route('titles.show', $title));
});

test('it badges a watched movie', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/search/multi*' => Http::response(searchMultiFixture())]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    Play::factory()->for($title, 'playable')->create();

    Livewire::test('pages::search')
        ->set('query', 'matrix')
        ->assertSeeHtml('bg-status-watched');
});

test('it badges a title on a list', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/search/multi*' => Http::response(searchMultiFixture())]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    $list = MediaList::factory()->create();
    $list->titles()->attach($title, ['position' => 0]);

    Livewire::test('pages::search')
        ->set('query', 'matrix')
        ->assertSee(__('On list'));
});

test('it badges a followed show that has not been fully watched', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/search/multi*' => Http::response(searchMultiFixture())]);

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);
    Follow::factory()->for($title)->create();

    Livewire::test('pages::search')
        ->set('query', 'breaking')
        ->assertSee(__('Following'));
});

test('it badges a watched show only when an episode has been played', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/search/multi*' => Http::response(searchMultiFixture())]);

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);
    $season = Season::factory()->for($title)->create();
    $episode = Episode::factory()->for($season)->create(['title_id' => $title->id]);
    Play::factory()->for($episode, 'playable')->create();

    Livewire::test('pages::search')
        ->set('query', 'breaking')
        ->assertSeeHtml('bg-status-watched');
});

test('a failed tmdb search shows a graceful error instead of crashing', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/search/multi*' => Http::response(null, 500)]);

    Livewire::test('pages::search')
        ->set('query', 'matrix')
        ->assertOk()
        ->assertSee(__('Search failed. Please try again in a moment.'));
});

test('the results area is wired to show a skeleton while a search request is in flight', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/search/multi*' => Http::response(searchMultiFixture())]);

    Livewire::test('pages::search')
        ->set('query', 'matrix')
        ->assertSeeHtml('wire:loading')
        ->assertSeeHtml('wire:target="query"')
        ->assertSeeHtml('animate-pulse');
});

test('repeated identical searches hit tmdb only once thanks to caching', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/search/multi*' => Http::response(searchMultiFixture())]);

    Livewire::test('pages::search')->set('query', 'matrix');
    Livewire::test('pages::search')->set('query', 'Matrix');
    Livewire::test('pages::search')->set('query', '  matrix  ');

    Http::assertSentCount(1);
});

test('it runs the search from the q query string submitted by the nav', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/search/multi*' => Http::response(searchMultiFixture())]);

    Livewire::withQueryParams(['q' => 'matrix'])
        ->test('pages::search')
        ->assertSet('query', 'matrix')
        ->assertSee('The Matrix');
});
