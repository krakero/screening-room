<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

dataset('nav_routes', [
    'dashboard' => ['dashboard'],
    'discover' => ['discover'],
    'search' => ['search'],
    'calendar' => ['calendar'],
    'history' => ['history'],
    'lists.index' => ['lists.index'],
]);

test('guests are redirected to the login page', function (string $routeName) {
    $response = $this->get(route($routeName));

    $response->assertRedirect(route('login'));
})->with('nav_routes');

test('authenticated users can visit the page', function (string $routeName) {
    Http::fake(['api.themoviedb.org/*' => Http::response(['results' => []])]);

    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route($routeName));

    $response->assertOk();
})->with('nav_routes');

test('the calendar page renders without leaking blade directives', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('calendar'))
        ->assertOk()
        ->assertSee(__('Calendar'))
        ->assertDontSee('@if', false);
});
