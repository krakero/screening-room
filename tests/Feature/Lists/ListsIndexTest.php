<?php

use App\Models\MediaList;
use App\Models\Title;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('lists.index'));

    $response->assertRedirect(route('login'));
});

test('the watchlist always appears first', function () {
    $this->actingAs(User::factory()->create());

    MediaList::watchlist();
    MediaList::factory()->create(['name' => 'Aardvark Favorites']);

    $lists = Livewire::test('pages::lists.index')->instance()->lists;

    expect($lists->first()->is_watchlist)->toBeTrue();
});

test('a new list can be created', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::lists.index')
        ->set('name', 'Halloween Marathon')
        ->call('createList')
        ->assertHasNoErrors();

    expect(MediaList::where('name', 'Halloween Marathon')->exists())->toBeTrue();
});

test('a list name is required to create a list', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::lists.index')
        ->set('name', '')
        ->call('createList')
        ->assertHasErrors(['name' => ['required']]);
});

test('a custom list can be renamed', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create(['name' => 'Old Name']);

    Livewire::test('pages::lists.index')
        ->call('startRenaming', $mediaList->id)
        ->set('editingName', 'New Name')
        ->call('renameList')
        ->assertHasNoErrors();

    expect($mediaList->fresh()->name)->toBe('New Name');
});

test('the watchlist cannot be renamed', function () {
    $this->actingAs(User::factory()->create());

    $watchlist = MediaList::watchlist();

    Livewire::test('pages::lists.index')
        ->call('startRenaming', $watchlist->id)
        ->assertForbidden();
});

test('a custom list can be deleted', function () {
    $this->actingAs(User::factory()->create());

    $mediaList = MediaList::factory()->create();

    Livewire::test('pages::lists.index')
        ->call('confirmDelete', $mediaList->id)
        ->call('deleteList');

    expect(MediaList::find($mediaList->id))->toBeNull();
});

test('the watchlist cannot be deleted', function () {
    $this->actingAs(User::factory()->create());

    $watchlist = MediaList::watchlist();

    Livewire::test('pages::lists.index')
        ->call('confirmDelete', $watchlist->id)
        ->assertForbidden();

    expect(MediaList::find($watchlist->id))->not->toBeNull();
});

test('list cards preview the first five posters in list order with an overflow count', function () {
    config(['services.tmdb.image_base_url' => 'https://image.tmdb.org/t/p']);
    $this->actingAs(User::factory()->create());

    $list = MediaList::factory()->create(['name' => 'Heist Night']);

    foreach (range(1, 7) as $position) {
        $list->titles()->attach(
            Title::factory()->create(['poster_path' => "/poster-{$position}.jpg"]),
            ['position' => $position],
        );
    }

    $this->get(route('lists.index'))
        ->assertOk()
        ->assertSeeInOrder([
            'https://image.tmdb.org/t/p/w154/poster-1.jpg',
            'https://image.tmdb.org/t/p/w154/poster-5.jpg',
            '+2',
        ], false)
        ->assertDontSee('https://image.tmdb.org/t/p/w154/poster-6.jpg', false);
});

test('empty lists show placeholder tiles instead of posters', function () {
    $this->actingAs(User::factory()->create());

    MediaList::factory()->create(['name' => 'Someday']);

    $this->get(route('lists.index'))
        ->assertOk()
        ->assertSee('border-dashed', false);
});
