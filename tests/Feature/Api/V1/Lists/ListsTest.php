<?php

use App\Models\MediaList;
use App\Models\Title;
use App\Models\User;

test('lists index requires authentication', function () {
    $this->getJson('/api/v1/lists')->assertUnauthorized();
});

test('lists index returns the contract shape ordered watchlist first then by name', function () {
    $user = User::factory()->create();
    $watchlist = MediaList::watchlist();
    $zebra = MediaList::factory()->create(['name' => 'Zebra']);
    $apple = MediaList::factory()->create(['name' => 'Apple']);

    $title = Title::factory()->create();
    $watchlist->titles()->attach($title, ['position' => 0]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/lists');

    $response->assertOk();
    expect($response->json('data.*.id'))->toBe([$watchlist->id, $apple->id, $zebra->id]);

    $response->assertJsonFragment([
        'id' => $watchlist->id,
        'name' => 'Watchlist',
        'slug' => 'watchlist',
        'is_watchlist' => true,
        'items_count' => 1,
    ]);

    expect($response->json('data.0.preview_posters'))->toBe([$title->posterUrl()]);
});

test('a list can be created', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/lists', ['name' => 'Favorites']);

    $response->assertCreated()->assertJson([
        'list' => ['name' => 'Favorites', 'slug' => 'favorites', 'is_watchlist' => false, 'items_count' => 0],
    ]);

    expect(MediaList::where('slug', 'favorites')->exists())->toBeTrue();
});

test('creating a list requires a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/lists', [])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
});

test('a custom list can be renamed', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/lists/{$mediaList->id}", ['name' => 'New Name']);

    $response->assertOk()->assertJson(['list' => ['name' => 'New Name']]);
    expect($mediaList->fresh()->name)->toBe('New Name');
});

test('the watchlist cannot be renamed', function () {
    $user = User::factory()->create();
    $watchlist = MediaList::watchlist();

    $this->actingAs($user, 'sanctum')->putJson("/api/v1/lists/{$watchlist->id}", ['name' => 'Nope'])
        ->assertForbidden();
});

test('a custom list can be deleted', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();

    $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/lists/{$mediaList->id}")
        ->assertNoContent();

    expect(MediaList::find($mediaList->id))->toBeNull();
});

test('the watchlist cannot be deleted', function () {
    $user = User::factory()->create();
    $watchlist = MediaList::watchlist();

    $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/lists/{$watchlist->id}")
        ->assertForbidden();

    expect(MediaList::find($watchlist->id))->not->toBeNull();
});
