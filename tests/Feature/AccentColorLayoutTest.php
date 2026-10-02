<?php

use App\Models\User;
use App\Support\AccentColor;

test('the layout renders the default amber accent when nothing is stored', function () {
    $this->actingAs(User::factory()->create(['accent' => null]));

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('data-accent="amber"', false);
    $response->assertDontSee('@if');
    $response->assertDontSee('accent-custom-override', false);
});

test('the layout renders a stored preset as a single data-accent attribute, no custom style block', function () {
    $this->actingAs(User::factory()->create(['accent' => 'sky']));

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('data-accent="sky"', false);
    $response->assertDontSee('accent-custom-override', false);
});

test('a preset with no dark override renders the same accent for both themes via CSS alone', function () {
    // Nothing to render server-side beyond the attribute itself — app.css only defines a
    // :root[data-accent="sky"] rule (no :root.dark[data-accent="sky"] override), so dark mode
    // falls back to the same value automatically. Covered by AccentColorTest for the CSS/PHP side;
    // this just confirms the layout doesn't emit a redundant custom style block for it.
    $this->actingAs(User::factory()->create(['accent' => 'sky']));

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('accent-custom-override', false);
});

test('a preset with a dark override still renders a single data-accent attribute', function () {
    // app.css's :root.dark[data-accent="violet"] rule handles the dark-mode swap entirely via CSS;
    // the layout itself only ever needs to render the one attribute, for any preset.
    $this->actingAs(User::factory()->create(['accent' => 'violet']));

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('data-accent="violet"', false);
    $response->assertDontSee('accent-custom-override', false);
});

test('the layout renders a stored custom hex color as a light/dark style block', function () {
    $user = User::factory()->create(['accent' => '#123456']);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $darkAccent = AccentColor::lightenForDarkCanvas('#123456');

    $response->assertOk();
    $response->assertDontSee('data-accent=', false);
    $response->assertSee('id="accent-custom-override"', false);
    $response->assertSee('--color-accent: #123456;', false);
    $response->assertSee('--color-accent: '.$darkAccent.';', false);
    expect($darkAccent)->not->toBe('#123456');
});

test('a custom hex that already reads fine on the dark canvas is not lightened', function () {
    // #a78bfa already clears AA against the dark canvas on its own.
    $user = User::factory()->create(['accent' => '#a78bfa']);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('--color-accent: #a78bfa;', false);

    // Both the light and dark blocks use the same, unmodified value.
    expect(substr_count($response->getContent(), '--color-accent: #a78bfa;'))->toBe(2);
});
