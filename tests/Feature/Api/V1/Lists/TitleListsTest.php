<?php

use App\Models\MediaList;
use App\Models\Title;
use App\Models\User;

test('a title lists requires authentication', function () {
    $title = Title::factory()->create();

    $this->getJson("/api/v1/titles/{$title->id}/lists")->assertUnauthorized();
});

test('a title reports its watchlist and custom list membership', function () {
    $user = User::factory()->create();
    $title = Title::factory()->create();
    $watchlist = MediaList::watchlist();
    $customList = MediaList::factory()->create(['name' => 'Favorites']);
    $otherList = MediaList::factory()->create(['name' => 'Later']);

    $title->mediaLists()->attach($watchlist, ['position' => 0]);
    $title->mediaLists()->attach($customList, ['position' => 0]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/titles/{$title->id}/lists");

    $response->assertOk()->assertJson([
        'watchlist' => ['id' => $watchlist->id, 'in_list' => true],
    ]);

    expect($response->json('custom_lists'))->toContain([
        'id' => $customList->id,
        'name' => 'Favorites',
        'in_list' => true,
    ])->toContain([
        'id' => $otherList->id,
        'name' => 'Later',
        'in_list' => false,
    ]);
});

test('toggling a list membership adds then removes the title', function () {
    $user = User::factory()->create();
    $title = Title::factory()->create();
    $mediaList = MediaList::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/lists/{$mediaList->id}/toggle");
    $response->assertOk()->assertJson(['in_list' => true]);
    expect($title->mediaLists()->where('media_lists.id', $mediaList->id)->exists())->toBeTrue();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/lists/{$mediaList->id}/toggle");
    $response->assertOk()->assertJson(['in_list' => false]);
    expect($title->mediaLists()->where('media_lists.id', $mediaList->id)->exists())->toBeFalse();
});

test('toggling the watchlist works the same as a custom list', function () {
    $user = User::factory()->create();
    $title = Title::factory()->create();
    $watchlist = MediaList::watchlist();

    $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/lists/{$watchlist->id}/toggle")
        ->assertOk()->assertJson(['in_list' => true]);

    expect($title->mediaLists()->where('media_lists.id', $watchlist->id)->exists())->toBeTrue();
});
