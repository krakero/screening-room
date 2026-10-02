<?php

use App\Enums\TitleType;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
});

test('import requires authentication', function () {
    $this->postJson('/api/v1/titles/import', ['type' => 'movie', 'tmdb_id' => 603])
        ->assertUnauthorized();
});

test('import validates type and tmdb_id', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->postJson('/api/v1/titles/import', ['type' => 'song', 'tmdb_id' => 'abc'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'tmdb_id']);
});

test('it imports a new movie from tmdb and returns 201 with the title resource', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    Http::fake([
        '*/movie/603*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/movie_603.json')), true)),
    ]);

    $response = $this->postJson('/api/v1/titles/import', ['type' => 'movie', 'tmdb_id' => 603]);

    $response->assertCreated()->assertJson(['type' => 'movie', 'tmdb_id' => 603]);

    expect(Title::where('tmdb_id', 603)->where('type', TitleType::Movie)->exists())->toBeTrue();
});

test('importing an already-imported title is idempotent and returns 200', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    $response = $this->postJson('/api/v1/titles/import', ['type' => 'movie', 'tmdb_id' => 603]);

    $response->assertOk()->assertJson(['id' => $title->id]);

    Http::assertNothingSent();
    expect(Title::where('tmdb_id', 603)->count())->toBe(1);
});

test('a concurrent import is guarded by a cache lock and returns 409', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $lock = Cache::lock('import-title:movie:603', 30);
    $lock->get();

    $response = $this->postJson('/api/v1/titles/import', ['type' => 'movie', 'tmdb_id' => 603]);

    $response->assertStatus(409);

    expect(Title::where('tmdb_id', 603)->exists())->toBeFalse();

    $lock->release();

    Http::assertNothingSent();
});
