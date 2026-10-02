<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

test('the sidebar renders the primary links', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(__('Up Next'));
    $response->assertSee(__('Discover'));
    $response->assertSee(__('Calendar'));
    $response->assertSee(__('History'));
    $response->assertSee(__('Lists'));
    $response->assertSee(__('Stats'));
});

test('the global search form submits to the search route', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('action="'.route('search').'"', false);
    $response->assertSee('name="q"', false);
});

test('the desktop user menu renders in the sidebar', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('data-test="user-menu-button"', false);
});

test('non hero pages keep the page container', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('data-flux-container', false);
    $response->assertSee('mx-auto w-full max-w-7xl px-6 lg:px-8', false);
});

test('the shared page container renders on every app page and matches the nav width', function () {
    Http::fake(['api.themoviedb.org/*' => Http::response(['results' => []])]);

    $user = User::factory()->create();

    $routes = [
        route('dashboard'),
        route('discover'),
        route('calendar'),
        route('history'),
        route('lists.index'),
        route('stats'),
        route('search'),
    ];

    foreach ($routes as $route) {
        $response = $this->actingAs($user)->get($route);

        $response->assertOk();
        $response->assertDontSee('@if', false);
        $response->assertSee('data-flux-container', false);
        $response->assertSee('mx-auto w-full max-w-7xl px-6 lg:px-8', false);
    }
});

test('the mobile bottom tab bar links to the primary pages', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('aria-label="'.__('Primary').'"', false);
    $response->assertSee(route('discover'), false);
    $response->assertSee(route('search'), false);
    $response->assertSee(route('lists.index'), false);
});

test('the mobile more menu links to calendar and history', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(route('calendar'), false);
    $response->assertSee(route('history'), false);
});

test('the user menu offers an appearance switch and log out', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(__('Appearance'));
    $response->assertSee(__('Log out'));
});

test('the appearance seed script is not broken by a nested script tag', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    $seedScript = Str::before(Str::after($html, 'Dark by default'), '</script>');

    expect($seedScript)->toContain("setItem('flux.appearance', 'dark')");
});
