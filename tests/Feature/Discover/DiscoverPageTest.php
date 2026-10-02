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
    config(['services.tmdb.region' => 'US']);

    Http::preventStrayRequests();
});

function discoverPageFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/Fixtures/tmdb/{$name}.json")),
        true,
    );
}

function fakeAllDiscoverPageEndpoints(): void
{
    Http::fake([
        '*/trending/all/week*' => Http::response(discoverPageFixture('trending_all_week')),
        '*/movie/now_playing*' => Http::response(discoverPageFixture('movie_now_playing')),
        '*/movie/upcoming*' => Http::response(discoverPageFixture('movie_upcoming')),
        '*/tv/on_the_air*' => Http::response(discoverPageFixture('tv_on_the_air')),
    ]);
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('discover'));

    $response->assertRedirect(route('login'));
});

test('the page renders every section in a single request without leaking blade directives', function () {
    $this->actingAs(User::factory()->create());

    fakeAllDiscoverPageEndpoints();

    $response = $this->get(route('discover'));

    $response->assertOk()
        ->assertSee(__('Discover'))
        ->assertSee(__('Trending this week'))
        ->assertSee(__('In theaters & coming soon'))
        ->assertSee(__('New episodes this week'))
        ->assertDontSee('@if', false);
});

test('loading the trending shelf points a title not yet imported at the passive-import route and never shows an add button', function () {
    $this->actingAs(User::factory()->create());

    fakeAllDiscoverPageEndpoints();

    Livewire::test('pages::discover')
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
            'year' => '1999',
        ]));
});

test('hide what I have seen filters out only watched titles, not merely imported ones, from the trending shelf', function () {
    $this->actingAs(User::factory()->create());

    fakeAllDiscoverPageEndpoints();

    Title::factory()->show()->create(['tmdb_id' => 1396, 'name' => 'Breaking Bad']);

    Livewire::test('pages::discover')
        ->assertSet('hideWatched', true)
        ->assertSee('The Matrix')
        ->assertSee('Breaking Bad');
});

test('hide what I have seen filters a watched title client-side, without a Livewire round trip', function () {
    $this->actingAs(User::factory()->create());

    fakeAllDiscoverPageEndpoints();

    $show = Title::factory()->show()->create(['tmdb_id' => 1396, 'name' => 'Breaking Bad']);
    $season = Season::factory()->for($show)->create();
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id]);
    Play::factory()->for($episode, 'playable')->create();

    // The play above makes this show a "because you watched" seed, so its recommendations
    // endpoint is now also hit on the same single-pass render.
    Http::fake(['*/tv/1396/recommendations*' => Http::response(['results' => []])]);

    // Both titles are always server-rendered; visibility is toggled purely client-side
    // via Alpine's x-show, baked from each item's watched flag.
    Livewire::test('pages::discover')
        ->assertSet('hideWatched', true)
        ->assertSee('The Matrix')
        ->assertSee('Breaking Bad')
        ->assertDontSee('wire:model.live="hideWatched"', false)
        ->assertSee('wire:key="trending-movie-603" x-show="!hideWatched || true"', false)
        ->assertSee('wire:key="trending-show-1396" x-show="!hideWatched || false"', false);
});

test('the trending shelf badges a title on a list or followed instead of showing an in-library badge', function () {
    $this->actingAs(User::factory()->create());

    fakeAllDiscoverPageEndpoints();

    $show = Title::factory()->show()->create(['tmdb_id' => 1396, 'name' => 'Breaking Bad']);
    Follow::factory()->for($show)->create();

    $movie = Title::factory()->movie()->create(['tmdb_id' => 603, 'name' => 'The Matrix']);
    $list = MediaList::factory()->create();
    $list->titles()->attach($movie, ['position' => 0]);

    Livewire::test('pages::discover')
        ->assertDontSee(__('In library'))
        ->assertSee(__('On list'))
        ->assertSee(__('Following'));
});

test('a failed trending shelf shows a shelf-level error while the other sections still render', function () {
    $this->actingAs(User::factory()->create());

    Http::fake([
        '*/trending/all/week*' => Http::response(null, 500),
        '*/movie/now_playing*' => Http::response(discoverPageFixture('movie_now_playing')),
        '*/movie/upcoming*' => Http::response(discoverPageFixture('movie_upcoming')),
        '*/tv/on_the_air*' => Http::response(discoverPageFixture('tv_on_the_air')),
    ]);

    Livewire::test('pages::discover')
        ->assertOk()
        ->assertSee(__('Could not load trending titles right now.'))
        ->assertSee(__('In theaters & coming soon'))
        ->assertDontSee('@if', false);
});

test('an empty because you watched result renders no shelf', function () {
    $this->actingAs(User::factory()->create());

    fakeAllDiscoverPageEndpoints();

    Livewire::test('pages::discover')
        ->assertOk()
        ->assertSet('recommendations', ['shelves' => [], 'error' => null]);

    Http::assertSentCount(4);
});

test('the in theaters and coming soon shelf renders under its renamed heading', function () {
    $this->actingAs(User::factory()->create());

    fakeAllDiscoverPageEndpoints();

    $response = $this->get(route('discover'));

    $response->assertOk()
        ->assertSee(__('In theaters & coming soon'))
        ->assertDontSee('New & upcoming movies')
        ->assertDontSee('@if', false);
});

test('the in theaters and coming soon shelf badges a re-release but not a current release or an upcoming title', function () {
    $this->actingAs(User::factory()->create());

    Http::fake([
        '*/trending/all/week*' => Http::response(discoverPageFixture('trending_all_week')),
        '*/movie/now_playing*' => Http::response(discoverPageFixture('movie_now_playing_with_re_release')),
        '*/movie/upcoming*' => Http::response(discoverPageFixture('movie_upcoming_empty')),
        '*/tv/on_the_air*' => Http::response(discoverPageFixture('tv_on_the_air')),
    ]);

    Livewire::test('pages::discover')
        ->assertOk()
        ->assertSee('The Devils')
        ->assertSee('Fresh Release')
        ->assertSeeInOrder([__('Re-release'), 'The Devils'])
        ->assertDontSee('@if', false)
        ->assertSet('newMovies.items.0.is_re_release', false)
        ->assertSet('newMovies.items.1.is_re_release', true)
        ->assertSet('newMovies.items.2.is_re_release', false);
});

test('nothing large ends up in the component snapshot sent back to the browser', function () {
    $this->actingAs(User::factory()->create());

    fakeAllDiscoverPageEndpoints();

    $snapshotSize = strlen(json_encode(Livewire::test('pages::discover')->snapshot));

    expect($snapshotSize)->toBeLessThan(5000);
});
