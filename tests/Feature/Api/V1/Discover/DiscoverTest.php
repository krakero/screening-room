<?php

use App\Models\LibraryStatus;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
    config(['services.tmdb.region' => 'US']);
});

function apiDiscoverFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/tmdb/{$name}.json")), true);
}

test('discover trending requires authentication', function () {
    $this->getJson('/api/v1/discover/trending')->assertUnauthorized();
});

test('discover trending returns items in the contract shape', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    Http::fake(['*/trending/all/week*' => Http::response(apiDiscoverFixture('trending_all_week'))]);

    $inLibrary = Title::factory()->show()->create(['tmdb_id' => 1396]);
    LibraryStatus::factory()->available()->create(['title_id' => $inLibrary->id]);

    $response = $this->getJson('/api/v1/discover/trending');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment([
            'tmdb_id' => 603,
            'type' => 'movie',
            'name' => 'The Matrix',
            'title_id' => null,
        ])
        ->assertJsonFragment([
            'tmdb_id' => 1396,
            'title_id' => $inLibrary->id,
            'status' => 'available',
        ]);
});

test('discover new returns in-theaters and upcoming movies', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    Http::fake([
        '*/movie/now_playing*' => Http::response(apiDiscoverFixture('movie_now_playing')),
        '*/movie/upcoming*' => Http::response(apiDiscoverFixture('movie_upcoming_empty')),
    ]);

    $this->getJson('/api/v1/discover/new')->assertOk()->assertJsonStructure(['data']);
});

test('discover new-episodes returns shows currently on the air', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    Http::fake(['*/tv/on_the_air*' => Http::response(apiDiscoverFixture('tv_on_the_air'))]);

    $this->getJson('/api/v1/discover/new-episodes')->assertOk()->assertJsonStructure(['data']);
});

test('discover recommendations is empty with no watch history and nests shelves', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson('/api/v1/discover/recommendations')->assertOk()->assertExactJson(['data' => []]);
});

test('a tmdb failure on trending returns 503', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    Http::fake(['*/trending/all/week*' => Http::response(null, 500)]);

    $this->getJson('/api/v1/discover/trending')->assertStatus(503);
});
