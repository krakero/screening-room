<?php

use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
});

function searchApiFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/search_multi.json')), true);
}

test('search requires authentication', function () {
    $this->getJson('/api/v1/search?q=matrix')->assertUnauthorized();
});

test('search requires a query', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson('/api/v1/search')->assertUnprocessable()->assertJsonValidationErrors('q');
});

test('search returns unimported and imported results in the contract shape', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    Http::fake(['*/search/multi*' => Http::response(searchApiFixture())]);

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);

    $response = $this->getJson('/api/v1/search?q=matrix');

    $response->assertOk()
        ->assertJsonFragment([
            'tmdb_id' => 603,
            'type' => 'movie',
            'name' => 'The Matrix',
            'year' => '1999',
            'title_id' => null,
            'href_action' => 'import',
        ])
        ->assertJsonFragment([
            'tmdb_id' => 1396,
            'type' => 'show',
            'name' => 'Breaking Bad',
            'title_id' => $title->id,
            'href_action' => 'show',
        ]);
});

test('repeated identical searches hit tmdb only once thanks to caching', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    Http::fake(['*/search/multi*' => Http::response(searchApiFixture())]);

    $this->getJson('/api/v1/search?q=matrix')->assertOk();
    $this->getJson('/api/v1/search?q=Matrix')->assertOk();
    $this->getJson('/api/v1/search?q='.urlencode('  matrix  '))->assertOk();

    Http::assertSentCount(1);
});

test('a tmdb failure returns a 503 with a friendly message', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    Http::fake(['*/search/multi*' => Http::response(null, 500)]);

    $this->getJson('/api/v1/search?q=matrix')->assertStatus(503);
});
