<?php

use App\Enums\CollectionFormat;
use App\Enums\TitleType;
use App\Models\CollectionItem;
use App\Models\Title;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($this->user);
});

test('collection page renders', function () {
    $response = $this->get(route('collection.index'));

    $response->assertOk();
    $response->assertSeeLivewire('pages::collection');
});

test('collection page displays owned titles', function () {
    $title = Title::factory()->movie()->create(['name' => 'Owned Movie']);
    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::BluRay]);

    Livewire::test('pages::collection')
        ->assertSee('Owned Movie')
        ->assertSee('Collection');
});

test('collection page can filter by format', function () {
    $blurayTitle = Title::factory()->movie()->create(['name' => 'Blu-ray Movie']);
    $digitalTitle = Title::factory()->movie()->create(['name' => 'Digital Movie']);

    CollectionItem::factory()->for($blurayTitle)->create(['format' => CollectionFormat::BluRay]);
    CollectionItem::factory()->for($digitalTitle)->create(['format' => CollectionFormat::Digital]);

    Livewire::test('pages::collection')
        ->set('formatFilter', CollectionFormat::BluRay->value)
        ->assertSee('Blu-ray Movie')
        ->assertDontSee('Digital Movie');
});

test('collection page can filter by type', function () {
    $movie = Title::factory()->movie()->create(['name' => 'Test Movie']);
    $show = Title::factory()->show()->create(['name' => 'Test Show']);

    CollectionItem::factory()->for($movie)->create();
    CollectionItem::factory()->for($show)->create();

    Livewire::test('pages::collection')
        ->set('typeFilter', TitleType::Movie->value)
        ->assertSee('Test Movie')
        ->assertDontSee('Test Show');
});

test('collection page can filter by loaned status', function () {
    $loaned = Title::factory()->movie()->create(['name' => 'Loaned Movie']);
    $notLoaned = Title::factory()->movie()->create(['name' => 'Available Movie']);

    CollectionItem::factory()->for($loaned)->create(['loaned_to' => 'Friend']);
    CollectionItem::factory()->for($notLoaned)->create(['loaned_to' => null]);

    Livewire::test('pages::collection')
        ->set('loanedFilter', 'true')
        ->assertSee('Loaned Movie')
        ->assertDontSee('Available Movie');
});

test('collection page can search titles', function () {
    $matrix = Title::factory()->movie()->create(['name' => 'The Matrix']);
    $inception = Title::factory()->movie()->create(['name' => 'Inception']);

    CollectionItem::factory()->for($matrix)->create();
    CollectionItem::factory()->for($inception)->create();

    Livewire::test('pages::collection')
        ->set('search', 'Matrix')
        ->assertSee('The Matrix')
        ->assertDontSee('Inception');
});

test('collection page can sort by title', function () {
    $zTitle = Title::factory()->movie()->create(['name' => 'Zebra Movie']);
    $aTitle = Title::factory()->movie()->create(['name' => 'Alpha Movie']);

    CollectionItem::factory()->for($zTitle)->create();
    CollectionItem::factory()->for($aTitle)->create();

    $component = Livewire::test('pages::collection')
        ->set('sort', 'title:asc');

    $titles = $component->get('titles');
    expect($titles->first()->name)->toBe('Alpha Movie');
});

test('collection page can sort by acquired date', function () {
    $old = Title::factory()->movie()->create(['name' => 'Old Movie']);
    $new = Title::factory()->movie()->create(['name' => 'New Movie']);

    CollectionItem::factory()->for($old)->create(['acquired_at' => now()->subYear()]);
    CollectionItem::factory()->for($new)->create(['acquired_at' => now()]);

    $component = Livewire::test('pages::collection')
        ->set('sort', 'acquired_at:desc');

    $titles = $component->get('titles');
    expect($titles->first()->name)->toBe('New Movie');
});

test('collection page displays totals', function () {
    $movie1 = Title::factory()->movie()->create();
    $movie2 = Title::factory()->movie()->create();
    $show = Title::factory()->show()->create();

    CollectionItem::factory()->for($movie1)->create();
    CollectionItem::factory()->for($movie2)->create(['loaned_to' => 'Friend']);
    CollectionItem::factory()->for($show)->create();

    $component = Livewire::test('pages::collection');

    $totals = $component->get('totals');
    expect($totals['titles'])->toBe(3);
    expect($totals['movies'])->toBe(2);
    expect($totals['shows'])->toBe(1);
    expect($totals['copies'])->toBe(3);
    expect($totals['loaned'])->toBe(1);

    $component->assertSeeInOrder(['titles', 'movies', 'show', 'copies', 'loaned out']);
});

test('collection page shows empty state when no titles', function () {
    Livewire::test('pages::collection')
        ->assertSee('No titles in your collection')
        ->assertSee('Start building your collection');
});

test('collection page returns 404 when collection is disabled', function () {
    $this->user->update(['collection_enabled' => false]);

    $response = $this->get(route('collection.index'));

    $response->assertNotFound();
});

test('collection nav item is hidden when collection is disabled', function () {
    $this->user->update(['collection_enabled' => false]);

    $response = $this->get(route('dashboard'));

    $response->assertDontSee('Collection');
});

test('collection nav item is visible when collection is enabled', function () {
    $title = Title::factory()->movie()->create();
    CollectionItem::factory()->for($title)->create();

    $response = $this->get(route('collection.index'));

    $response->assertSee('Collection');
});

test('can clear all filters', function () {
    $title = Title::factory()->movie()->create();
    CollectionItem::factory()->for($title)->create();

    Livewire::test('pages::collection')
        ->set('formatFilter', CollectionFormat::BluRay->value)
        ->set('search', 'test')
        ->call('clearFilters')
        ->assertSet('formatFilter', '')
        ->assertSet('search', '');
});

test('pagination resets when filters change', function () {
    foreach (range(1, 30) as $i) {
        $title = Title::factory()->movie()->create();
        CollectionItem::factory()->for($title)->create();
    }

    $component = Livewire::test('pages::collection')
        ->call('gotoPage', 2, 'page')
        ->assertSet('paginators.page', 2)
        ->set('formatFilter', CollectionFormat::BluRay->value)
        ->assertSet('paginators.page', 1);
});
