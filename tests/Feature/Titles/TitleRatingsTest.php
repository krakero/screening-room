<?php

use App\Models\ExternalRating;
use App\Models\Title;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->actingAs(User::factory()->create());
    app(IntegrationSettings::class)->set('mdblist.api_key', 'test-key');
});

test('the hero shows cached external rating badges in a fixed order', function () {
    $title = Title::factory()->movie()->create(['ratings_checked_at' => now()]);

    ExternalRating::factory()->for($title)->create(['source' => 'trakt', 'value' => 87, 'max' => 100]);
    ExternalRating::factory()->for($title)->create(['source' => 'imdb', 'value' => 8.7, 'max' => 10]);
    ExternalRating::factory()->for($title)->create(['source' => 'tomatoes', 'value' => 83, 'max' => 100]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSeeInOrder(['IMDb', '8.7', 'RT', '83', 'Trakt', '87']);
    $response->assertDontSee('@if');
    Http::assertNothingSent();
});

test('fresh ratings are not refetched', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt0133093', 'ratings_checked_at' => now()->subDays(2)]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    Http::assertNothingSent();
});

test('a never-checked title is fetched once, in the background', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt0133093', 'ratings_checked_at' => null]);

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt0133093/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/mdblist/movie_603.json')), true),
        ),
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    Http::assertSentCount(1);
    expect($title->refresh()->ratings_checked_at)->not->toBeNull();
    expect(ExternalRating::where('title_id', $title->id)->count())->toBeGreaterThan(0);
});

test('a stale title is refetched', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt0133093', 'ratings_checked_at' => now()->subDays(8)]);

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt0133093/*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/mdblist/movie_603.json')), true),
        ),
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    Http::assertSentCount(1);
});

test('a not-found title is recorded and not re-requested within 7 days', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt9999999', 'ratings_checked_at' => null]);

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt9999999/*' => Http::response(['id' => 0, 'title' => 'Unknown', 'ratings' => []]),
    ]);

    $this->get(route('titles.show', $title))->assertOk();

    expect(ExternalRating::where('title_id', $title->id)->count())->toBe(0);
    expect($title->refresh()->ratings_checked_at)->not->toBeNull();

    // Second view within the week should not fetch again.
    $this->get(route('titles.show', $title))->assertOk();

    Http::assertSentCount(1);
});

test('no rating badges render when the title has no external ratings', function () {
    $title = Title::factory()->movie()->create(['ratings_checked_at' => now()]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('IMDb');
    $response->assertDontSee('@if');
});
