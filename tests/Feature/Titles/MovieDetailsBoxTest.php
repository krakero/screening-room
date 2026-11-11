<?php

use App\Models\Title;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('details box shows full release date for a movie', function () {
    $title = Title::factory()->movie()->create([
        'release_date' => '2025-03-14',
        'certification' => 'PG-13',
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Mar 14, 2025');
    $response->assertSee('PG-13');
});

test('details box shows only release date when certification is missing', function () {
    $title = Title::factory()->movie()->create([
        'release_date' => '2025-03-14',
        'certification' => null,
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Mar 14, 2025');
    $response->assertDontSee('Rated');
});

test('details box shows only rating when release date is missing', function () {
    $title = Title::factory()->movie()->create([
        'release_date' => null,
        'certification' => 'R',
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Rated');
    $response->assertSee('>R<', false);
    $response->assertDontSee('Released');
});

test('details box is absent when all details fields are missing', function () {
    $title = Title::factory()->movie()->create([
        'release_date' => null,
        'certification' => null,
        'director' => null,
        'imdb_id' => null,
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('Released');
    $response->assertDontSee('Rated');
    $response->assertDontSee('Director');
    $response->assertDontSee('IMDb');
});

test('details box is absent for shows', function () {
    $title = Title::factory()->show()->create([
        'release_date' => '2020-01-01',
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('Released');
});

test('director row shows when director is set', function () {
    $title = Title::factory()->movie()->create(['director' => 'Lana Wachowski, Lilly Wachowski']);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Director');
    $response->assertSee('Lana Wachowski, Lilly Wachowski');
});

test('director row is hidden when director is null', function () {
    $title = Title::factory()->movie()->create(['director' => null]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('Director');
});

test('imdb link shows with correct href when imdb_id is set', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => 'tt0133093']);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('IMDb ↗');
    $response->assertSee('https://www.imdb.com/title/tt0133093/', false);
});

test('imdb link is hidden when imdb_id is null', function () {
    $title = Title::factory()->movie()->create(['imdb_id' => null]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('imdb.com', false);
});

test('year chip still shows only the year for a movie', function () {
    $title = Title::factory()->movie()->create([
        'release_date' => '1999-03-30',
        'certification' => 'R',
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    // The hero chip still shows just the year
    $response->assertSee('1999');
    // The full date "Mar 30, 1999" appears in the details box — that is correct — but the
    // chip block in the hero must not contain a date range like "1999–1999"
    $response->assertDontSee('1999–1999');
});
