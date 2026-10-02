<?php

use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.features.collection'));

    $response->assertRedirect(route('login'));
});

test('the collection settings page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.features.collection'));

    $response->assertOk();
    $response->assertSee('Collection');
    $response->assertSee('Enable collection');
    $response->assertDontSee('@if');
});

test('the collection toggle persists when changed', function () {
    $user = User::factory()->create(['collection_enabled' => false]);

    $this->actingAs($user);

    Livewire::test('pages::settings.features.collection')
        ->assertSet('collectionEnabled', false)
        ->set('collectionEnabled', true)
        ->assertHasNoErrors();

    expect($user->fresh()->collection_enabled)->toBeTrue();

    Livewire::test('pages::settings.features.collection')
        ->assertSet('collectionEnabled', true)
        ->set('collectionEnabled', false)
        ->assertHasNoErrors();

    expect($user->fresh()->collection_enabled)->toBeFalse();
});

test('the page is accessible even when collection is disabled', function () {
    $user = User::factory()->create(['collection_enabled' => false]);

    $this->actingAs($user);

    $response = $this->get(route('settings.features.collection'));

    $response->assertOk();
});

test('the import card is shown when collection is enabled', function () {
    $user = User::factory()->create(['collection_enabled' => true]);

    $this->actingAs($user);

    $response = $this->get(route('settings.features.collection'));

    $response->assertOk();
    $response->assertSee('Import');
    $response->assertSee('Bulk import collection items from CSV');
});

test('the import card is hidden when collection is disabled', function () {
    $user = User::factory()->create(['collection_enabled' => false]);

    $this->actingAs($user);

    $response = $this->get(route('settings.features.collection'));

    $response->assertOk();
    $response->assertDontSee('Import');
});
