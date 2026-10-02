<?php

use App\Enums\CollectionFormat;
use App\Models\CollectionItem;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create(['collection_enabled' => true]);
    actingAs($this->user);
});

test('it renders when collection is enabled', function () {
    $title = Title::factory()->create();

    Livewire::test('collection-copies', ['title' => $title])
        ->assertSee('In your collection')
        ->assertSee('Add copy');
});

test('it does not render when collection is disabled', function () {
    $this->user->update(['collection_enabled' => false]);
    $title = Title::factory()->create();

    Livewire::test('collection-copies', ['title' => $title])
        ->assertDontSee('In your collection');
});

test('it shows empty state when no copies exist', function () {
    $title = Title::factory()->create();

    Livewire::test('collection-copies', ['title' => $title])
        ->assertSee('No copies yet')
        ->assertSee('Add physical or digital copies');
});

test('it can add a copy', function () {
    $title = Title::factory()->create();

    Livewire::test('collection-copies', ['title' => $title])
        ->set('format', CollectionFormat::BluRay->value)
        ->set('edition', 'Steelbook')
        ->set('retailer', 'Best Buy')
        ->call('save')
        ->assertHasNoErrors();

    expect($title->collectionItems()->count())->toBe(1);

    $item = $title->collectionItems()->first();
    expect($item->format)->toBe(CollectionFormat::BluRay)
        ->and($item->edition)->toBe('Steelbook')
        ->and($item->retailer)->toBe('Best Buy');
});

test('it can update a copy', function () {
    $title = Title::factory()->create();
    $item = CollectionItem::factory()->for($title)->create([
        'format' => CollectionFormat::BluRay,
        'edition' => 'Standard',
    ]);

    Livewire::test('collection-copies', ['title' => $title])
        ->call('openEditModal', $item->id)
        ->set('edition', 'Collector\'s Edition')
        ->call('save')
        ->assertHasNoErrors();

    expect($item->fresh()->edition)->toBe('Collector\'s Edition');
});

test('it can remove a copy', function () {
    $title = Title::factory()->create();
    $item = CollectionItem::factory()->for($title)->create();

    Livewire::test('collection-copies', ['title' => $title])
        ->call('confirmRemove', $item->id)
        ->call('remove');

    expect($title->collectionItems()->count())->toBe(0);
});

test('it validates required format', function () {
    $title = Title::factory()->create();

    Livewire::test('collection-copies', ['title' => $title])
        ->set('format', '')
        ->call('save')
        ->assertHasErrors(['format' => 'required']);
});

test('it validates format is valid enum', function () {
    $title = Title::factory()->create();

    Livewire::test('collection-copies', ['title' => $title])
        ->set('format', 'invalid')
        ->call('save')
        ->assertHasErrors('format');
});

test('it validates price is numeric', function () {
    $title = Title::factory()->create();

    Livewire::test('collection-copies', ['title' => $title])
        ->set('format', CollectionFormat::BluRay->value)
        ->set('price', 'abc')
        ->call('save')
        ->assertHasErrors('price');
});

test('it validates price is non-negative', function () {
    $title = Title::factory()->create();

    Livewire::test('collection-copies', ['title' => $title])
        ->set('format', CollectionFormat::BluRay->value)
        ->set('price', '-10')
        ->call('save')
        ->assertHasErrors('price');
});

test('it can attach a season copy for shows', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);

    Livewire::test('collection-copies', ['title' => $title])
        ->set('format', CollectionFormat::BluRay->value)
        ->set('seasonId', $season->id)
        ->call('save')
        ->assertHasNoErrors();

    $item = $title->collectionItems()->first();
    expect($item->season_id)->toBe($season->id);
});

test('it displays copies for a specific season', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);

    $seasonCopy = CollectionItem::factory()
        ->for($title)
        ->for($season)
        ->create(['format' => CollectionFormat::BluRay]);

    $titleCopy = CollectionItem::factory()
        ->for($title)
        ->create(['format' => CollectionFormat::Uhd4k]);

    $component = Livewire::test('collection-copies', ['title' => $title, 'season' => $season]);

    $copies = $component->get('copies');
    expect($copies)->toHaveCount(1);
    expect($copies->first()->format)->toBe(CollectionFormat::BluRay);
    expect($copies->first()->id)->toBe($seasonCopy->id);
});

test('it auto-sets season when mounted with season prop', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);

    Livewire::test('collection-copies', ['title' => $title, 'season' => $season])
        ->call('openAddModal')
        ->assertSet('seasonId', $season->id);
});

test('it displays loan information', function () {
    $title = Title::factory()->create();
    CollectionItem::factory()
        ->for($title)
        ->create([
            'format' => CollectionFormat::BluRay,
            'loaned_to' => 'John Doe',
            'loaned_at' => now()->subDays(7),
        ]);

    Livewire::test('collection-copies', ['title' => $title])
        ->assertSee('Loaned to John Doe');
});

test('it displays location information', function () {
    $title = Title::factory()->create();
    CollectionItem::factory()
        ->for($title)
        ->create([
            'format' => CollectionFormat::BluRay,
            'location' => 'Living room shelf',
        ]);

    Livewire::test('collection-copies', ['title' => $title])
        ->assertSee('Living room shelf');
});

test('it displays plex availability', function () {
    $title = Title::factory()->create();
    $title->plexItem()->create([
        'rating_key' => '12345',
        'machine_identifier' => 'test-machine',
        'synced_at' => now(),
        'checked_at' => now(),
    ]);

    $component = Livewire::test('collection-copies', ['title' => $title]);

    expect($component->get('ownership')->inPlex)->toBeTrue();

    $component->assertSee('Plex')
        ->assertSee('Available in your Plex library');
});

test('it displays all format chips', function () {
    $title = Title::factory()->create();

    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::BluRay]);
    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::Uhd4k]);

    Livewire::test('collection-copies', ['title' => $title])
        ->assertSee('Blu-ray')
        ->assertSee('4K UHD');
});
