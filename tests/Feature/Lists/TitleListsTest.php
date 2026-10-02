<?php

use App\Livewire\TitleLists;
use App\Models\MediaList;
use App\Models\Title;
use App\Models\User;
use Livewire\Livewire;

test('the title lists component is mounted on the title page', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Add to List');
});

test('a title can be added to and removed from the watchlist', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $component = Livewire::test(TitleLists::class, ['title' => $title])
        ->assertSet('inWatchlist', false)
        ->call('toggleWatchlist')
        ->assertSet('inWatchlist', true);

    expect(MediaList::watchlist()->titles()->whereKey($title->id)->exists())->toBeTrue();

    $component->call('toggleWatchlist')->assertSet('inWatchlist', false);

    expect(MediaList::watchlist()->titles()->whereKey($title->id)->exists())->toBeFalse();
});

test('a title can be toggled in and out of a custom list', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    $mediaList = MediaList::factory()->create(['name' => 'Best of 2026']);

    Livewire::test(TitleLists::class, ['title' => $title])
        ->call('toggleList', $mediaList->id);

    expect($mediaList->titles()->whereKey($title->id)->exists())->toBeTrue();

    Livewire::test(TitleLists::class, ['title' => $title])
        ->call('toggleList', $mediaList->id);

    expect($mediaList->fresh()->titles()->whereKey($title->id)->exists())->toBeFalse();
});

test('a new list can be created inline and the title is added to it', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test(TitleLists::class, ['title' => $title])
        ->set('creatingList', true)
        ->set('newListName', 'Cozy Rewatches')
        ->call('createList')
        ->assertSet('creatingList', false)
        ->assertSet('newListName', '');

    $mediaList = MediaList::where('name', 'Cozy Rewatches')->first();

    expect($mediaList)->not->toBeNull()
        ->and($mediaList->slug)->toBe('cozy-rewatches')
        ->and($mediaList->titles()->whereKey($title->id)->exists())->toBeTrue();
});

test('creating a list requires a name', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test(TitleLists::class, ['title' => $title])
        ->set('newListName', '')
        ->call('createList')
        ->assertHasErrors(['newListName' => 'required']);
});

test('toggling a nonexistent list fails server-side and leaves membership unchanged', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test(TitleLists::class, ['title' => $title])
        ->call('toggleList', 999999)
        ->assertStatus(404);

    expect($title->mediaLists()->count())->toBe(0);
});
