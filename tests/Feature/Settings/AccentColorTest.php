<?php

use App\Livewire\AccentPicker;
use App\Models\User;
use App\Support\AccentColor;
use Livewire\Livewire;

test('the appearance settings page renders the accent picker', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('appearance.edit'));

    $response->assertOk();
    $response->assertSee('Accent color');
    $response->assertDontSee('@if');
});

test('guests cannot reach the appearance settings page', function () {
    $this->get(route('appearance.edit'))->assertRedirect(route('login'));
});

test('a swatch can be selected and is persisted', function () {
    $user = User::factory()->create(['accent' => null]);
    $this->actingAs($user);

    Livewire::test(AccentPicker::class)
        ->call('selectPreset', 'violet')
        ->assertDispatched(
            'accent-applied',
            preset: 'violet',
            light: ['accent' => '#7c3aed', 'foreground' => '#ffffff'],
            dark: ['accent' => '#a78bfa', 'foreground' => '#0a0a0a'],
        );

    expect($user->fresh()->accent)->toBe('violet');
});

test('a swatch with no dark override dispatches the same accent for both themes', function () {
    $user = User::factory()->create(['accent' => null]);
    $this->actingAs($user);

    Livewire::test(AccentPicker::class)
        ->call('selectPreset', 'teal')
        ->assertDispatched(
            'accent-applied',
            preset: 'teal',
            light: ['accent' => '#0d9488', 'foreground' => '#0a0a0a'],
            dark: ['accent' => '#0d9488', 'foreground' => '#0a0a0a'],
        );

    expect($user->fresh()->accent)->toBe('teal');
});

test('selecting an unknown preset is ignored', function () {
    $user = User::factory()->create(['accent' => 'violet']);
    $this->actingAs($user);

    Livewire::test(AccentPicker::class)
        ->call('selectPreset', 'not-a-real-preset');

    expect($user->fresh()->accent)->toBe('violet');
});

test('a custom hex color can be applied and is persisted, with a derived dark-mode variant', function () {
    $user = User::factory()->create(['accent' => null]);
    $this->actingAs($user);

    $darkAccent = AccentColor::lightenForDarkCanvas('#123456');

    Livewire::test(AccentPicker::class)
        ->set('customHex', '#123456')
        ->call('applyCustom')
        ->assertDispatched(
            'accent-applied',
            preset: null,
            light: ['accent' => '#123456', 'foreground' => AccentColor::foregroundFor('#123456')],
            dark: ['accent' => $darkAccent, 'foreground' => AccentColor::foregroundFor($darkAccent)],
        );

    expect($user->fresh()->accent)->toBe('#123456')
        ->and($darkAccent)->not->toBe('#123456');
});

test('an invalid custom hex color is not saved', function () {
    $user = User::factory()->create(['accent' => 'amber']);
    $this->actingAs($user);

    Livewire::test(AccentPicker::class)
        ->set('customHex', 'not-a-hex')
        ->call('applyCustom')
        ->assertNotDispatched('accent-applied');

    expect($user->fresh()->accent)->toBe('amber');
});

test('a low contrast custom color surfaces a warning', function () {
    $user = User::factory()->create(['accent' => null]);
    $this->actingAs($user);

    Livewire::test(AccentPicker::class)
        ->set('customHex', '#fdfdfd')
        ->assertSet('customHexIsLowContrast', true)
        ->set('customHex', '#7c3aed')
        ->assertSet('customHexIsLowContrast', false);
});

test('mounting the picker preloads a stored preset', function () {
    $user = User::factory()->create(['accent' => 'teal']);
    $this->actingAs($user);

    Livewire::test(AccentPicker::class)
        ->assertSet('accent', 'teal')
        ->assertSet('useCustom', false);
});

test('mounting the picker preloads a stored custom color', function () {
    $user = User::factory()->create(['accent' => '#334455']);
    $this->actingAs($user);

    Livewire::test(AccentPicker::class)
        ->assertSet('accent', '#334455')
        ->assertSet('customHex', '#334455')
        ->assertSet('useCustom', true);
});
