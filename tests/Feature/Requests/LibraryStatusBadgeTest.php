<?php

use App\Enums\LibraryState;
use App\Models\LibraryStatus;
use App\Models\MediaList;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('search results show the library status badge for a requested title', function () {
    $this->actingAs(User::factory()->create());

    Http::preventStrayRequests();

    $title = Title::factory()->movie()->create(['tmdb_id' => 603, 'name' => 'The Matrix']);
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Downloading]);

    Http::fake(['*/search/multi*' => Http::response([
        'results' => [
            ['id' => 603, 'media_type' => 'movie', 'title' => 'The Matrix', 'release_date' => '1999-03-31', 'poster_path' => null],
        ],
    ])]);

    Livewire::test('pages::search')
        ->set('query', 'matrix')
        ->assertSee(__('Downloading'));
});

test('list titles show their library status badge', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Available]);

    $list = MediaList::factory()->create();
    $list->items()->create(['title_id' => $title->id, 'position' => 0]);

    $response = $this->get(route('lists.show', $list));

    $response->assertOk()->assertSee(__('Available'));
});
