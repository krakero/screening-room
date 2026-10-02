<?php

use App\Enums\CollectionFormat;
use App\Models\CollectionItem;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;

test('collection index requires authentication', function () {
    $response = $this->getJson('/api/v1/collection');

    $response->assertUnauthorized();
});

test('collection endpoints return 404 when user has collection disabled', function () {
    $user = User::factory()->create(['collection_enabled' => false]);
    $title = Title::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/collection')
        ->assertNotFound();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/titles/{$title->id}/collection")
        ->assertNotFound();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/titles/{$title->id}/collection", ['format' => 'bluray'])
        ->assertNotFound();
});

test('collection index returns paginated items', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $title = Title::factory()->create();

    CollectionItem::factory()->count(25)->for($title)->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/collection');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title_id',
                    'title_name',
                    'title_type',
                    'title_year',
                    'title_poster_url',
                    'format',
                    'format_label',
                ],
            ],
            'links',
            'meta',
        ])
        ->assertJsonCount(20, 'data');
});

test('collection index filters by format', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $title = Title::factory()->create();

    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::BluRay]);
    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::Uhd4k]);
    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::Digital]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/collection?format=bluray');

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.format'))->toBe('bluray');
});

test('collection index filters by type', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $movie = Title::factory()->movie()->create();
    $show = Title::factory()->show()->create();

    CollectionItem::factory()->for($movie)->create();
    CollectionItem::factory()->for($show)->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/collection?type=movie');

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.title_type'))->toBe('movie');
});

test('collection index filters loaned items', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $title = Title::factory()->create();

    CollectionItem::factory()->for($title)->create(['loaned_to' => 'Friend']);
    CollectionItem::factory()->for($title)->create(['loaned_to' => null]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/collection?loaned=1');

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.loaned_to'))->toBe('Friend');
});

test('collection index searches by title name', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $inception = Title::factory()->create(['name' => 'Inception']);
    $interstellar = Title::factory()->create(['name' => 'Interstellar']);

    CollectionItem::factory()->for($inception)->create();
    CollectionItem::factory()->for($interstellar)->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/collection?q=Incep');

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.title_name'))->toBe('Inception');
});

test('titles collection endpoint returns ownership summary', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $title = Title::factory()->create();

    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::BluRay]);
    CollectionItem::factory()->for($title)->create(['format' => CollectionFormat::Uhd4k]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/titles/{$title->id}/collection");

    $response->assertOk()
        ->assertJsonStructure([
            'copies',
            'in_plex',
            'is_owned',
            'formats',
            'label',
        ]);

    expect($response->json('is_owned'))->toBeTrue();
    expect($response->json('copies'))->toHaveCount(2);
    expect($response->json('formats'))->toHaveCount(2);
});

test('can add item to collection', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $title = Title::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/collection", [
        'format' => 'bluray',
        'edition' => 'Steelbook',
        'retailer' => 'Best Buy',
        'acquired_at' => '2024-01-15',
        'price' => 29.99,
        'currency' => 'USD',
    ]);

    $response->assertCreated()
        ->assertJson([
            'title_id' => $title->id,
            'format' => 'bluray',
            'format_label' => 'Blu-ray',
            'edition' => 'Steelbook',
            'retailer' => 'Best Buy',
            'price' => 29.99,
            'currency' => 'USD',
        ]);

    expect(CollectionItem::count())->toBe(1);
});

test('adding item validates format is required', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $title = Title::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/collection", [
        'edition' => 'Steelbook',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('format');
});

test('adding item validates season belongs to title', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $show = Title::factory()->show()->create();
    $otherShow = Title::factory()->show()->create();
    $otherSeason = Season::factory()->for($otherShow)->create(['season_number' => 1]);

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$show->id}/collection", [
        'format' => 'bluray',
        'season_id' => $otherSeason->id,
    ]);

    $response->assertNotFound();
});

test('can update collection item', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $title = Title::factory()->create();
    $item = CollectionItem::factory()->for($title)->create([
        'format' => CollectionFormat::Dvd,
        'edition' => 'Standard',
    ]);

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/collection/{$item->id}", [
        'format' => 'bluray',
        'edition' => 'Collector Edition',
        'location' => 'Shelf A',
    ]);

    $response->assertOk()
        ->assertJson([
            'format' => 'bluray',
            'edition' => 'Collector Edition',
            'location' => 'Shelf A',
        ]);

    expect($item->fresh())
        ->format->toBe(CollectionFormat::BluRay)
        ->edition->toBe('Collector Edition')
        ->location->toBe('Shelf A');
});

test('can delete collection item', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $title = Title::factory()->create();
    $item = CollectionItem::factory()->for($title)->create();

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/collection/{$item->id}");

    $response->assertNoContent();
    expect(CollectionItem::count())->toBe(0);
});

test('validates price is numeric and non-negative', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $title = Title::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/collection", [
        'format' => 'bluray',
        'price' => -10,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('price');
});

test('validates dates are valid', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $title = Title::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/collection", [
        'format' => 'bluray',
        'acquired_at' => 'not-a-date',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('acquired_at');
});
