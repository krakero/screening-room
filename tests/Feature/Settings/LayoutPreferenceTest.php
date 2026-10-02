<?php

use App\Enums\AppLayout;
use App\Models\Title;
use App\Models\User;
use Livewire\Livewire;

test('the appearance settings page shows the layout options with the current value preselected', function () {
    $user = User::factory()->create(['layout' => null]);
    $this->actingAs($user);

    $response = $this->get(route('appearance.edit'));

    $response->assertOk();
    $response->assertSee(AppLayout::Header->label());
    $response->assertSee(AppLayout::Sidebar->label());
    $response->assertDontSee('@if');

    Livewire::test('pages::settings.appearance')
        ->assertSet('layout', AppLayout::Sidebar->value);
});

test('mounting preselects the stored layout', function () {
    $user = User::factory()->create(['layout' => AppLayout::Header]);
    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->assertSet('layout', AppLayout::Header->value);
});

test('saveLayout persists the chosen layout and redirects', function () {
    $user = User::factory()->create(['layout' => null]);
    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('layout', AppLayout::Header->value)
        ->call('saveLayout')
        ->assertRedirect(route('appearance.edit'));

    expect($user->fresh()->layout)->toBe(AppLayout::Header);

    Livewire::test('pages::settings.appearance')
        ->set('layout', AppLayout::Sidebar->value)
        ->call('saveLayout')
        ->assertRedirect(route('appearance.edit'));

    expect($user->fresh()->layout)->toBe(AppLayout::Sidebar);
});

test('saveLayout rejects an invalid value', function () {
    $user = User::factory()->create(['layout' => AppLayout::Header]);
    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('layout', 'not-a-real-layout')
        ->call('saveLayout')
        ->assertHasErrors(['layout']);

    expect($user->fresh()->layout)->toBe(AppLayout::Header);
});

test('the dashboard renders the sidebar layout for a null-layout user', function () {
    $this->actingAs(User::factory()->create(['layout' => null]));

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('data-flux-sidebar', false);
});

test('the dashboard renders the sidebar layout for a Sidebar user', function () {
    $this->actingAs(User::factory()->create(['layout' => AppLayout::Sidebar]));

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('data-flux-sidebar', false);
});

test('the dashboard renders the header layout for a Header user', function () {
    $this->actingAs(User::factory()->create(['layout' => AppLayout::Header]));

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('data-flux-sidebar', false);
    $response->assertSee(__('Up Next'));
    $response->assertSee(__('Discover'));
    $response->assertSee('name="q"', false);
});

test('a full-bleed title page renders data-full-bleed under both layouts', function (AppLayout $layout) {
    $this->actingAs(User::factory()->create(['layout' => $layout]));

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('data-full-bleed', false);
})->with([
    'header layout' => [AppLayout::Header],
    'sidebar layout' => [AppLayout::Sidebar],
]);
