<?php

use App\Enums\TitleType;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Js;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('titles.tmdb', ['type' => 'movie', 'tmdbId' => 603]));

    $response->assertRedirect(route('login'));
});

test('it redirects straight to the title page when the title already exists', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    Livewire::test('pages::titles.importing', ['type' => 'movie', 'tmdbId' => '603'])
        ->assertRedirect(route('titles.show', $title));

    Http::assertNothingSent();
});

test('it renders an importing skeleton with the passed-through tmdb data and starts the import on init', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('titles.tmdb', [
        'type' => 'movie',
        'tmdbId' => 603,
        'name' => 'The Matrix',
        'year' => '1999',
    ]));

    $response->assertOk()
        ->assertSee('The Matrix')
        ->assertSee('1999')
        ->assertSee(__('Adding to your library…'))
        ->assertSee('wire:init="import"', false)
        ->assertSee('role="status" aria-live="polite"', false)
        ->assertSee('size-12 animate-spin text-accent sm:size-16', false)
        ->assertDontSee('@if', false);

    Http::assertNothingSent();
});

test('wire:init import fetches the title from tmdb and replaces the history entry with the detail page', function () {
    $this->actingAs(User::factory()->create());

    Http::fake([
        '*/movie/603*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/movie_603.json')), true)),
    ]);

    $component = Livewire::test('pages::titles.importing', ['type' => 'movie', 'tmdbId' => '603'])
        ->call('import');

    $title = Title::where('tmdb_id', 603)->where('type', TitleType::Movie)->firstOrFail();

    $component->assertNoRedirect()
        ->assertJs('window.location.replace('.Js::from(route('titles.show', $title)).')');
});

test('calling import when the title was concurrently imported replaces the history entry instead of pushing a new one', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::titles.importing', ['type' => 'movie', 'tmdbId' => '603']);

    // Simulates another request finishing the import between mount() and this call.
    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    $component->call('import')
        ->assertNoRedirect()
        ->assertJs('window.location.replace('.Js::from(route('titles.show', $title)).')');

    Http::assertNothingSent();
});

test('a tmdb failure shows an inline error with a retry button instead of a 500', function () {
    $this->actingAs(User::factory()->create());

    Http::fake(['*/movie/603*' => Http::response(null, 500)]);

    Livewire::test('pages::titles.importing', ['type' => 'movie', 'tmdbId' => '603'])
        ->call('import')
        ->assertOk()
        ->assertNoRedirect()
        ->assertSee(__('Could not add this title from TMDB. Please try again.'))
        ->assertSee(__('Retry'));

    expect(Title::where('tmdb_id', 603)->exists())->toBeFalse();
});

test('a concurrent import is guarded by a cache lock and does not create a duplicate', function () {
    $this->actingAs(User::factory()->create());

    $lock = Cache::lock('import-title:movie:603', 30);
    $lock->get();

    Livewire::test('pages::titles.importing', ['type' => 'movie', 'tmdbId' => '603'])
        ->call('import')
        ->assertOk()
        ->assertNoRedirect()
        ->assertSee(__('This title is already being added. Please wait a moment and try again.'));

    expect(Title::where('tmdb_id', 603)->exists())->toBeFalse();

    $lock->release();

    Http::assertNothingSent();
});
