<?php

use App\Models\Episode;
use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Models\Network;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Models\WatchProvider;
use App\Support\IntegrationSettings;

test('list items requires authentication', function () {
    $mediaList = MediaList::factory()->create();

    $this->getJson("/api/v1/lists/{$mediaList->id}/items")->assertUnauthorized();
});

test('list items are returned in position order with the contract shape', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();
    $first = Title::factory()->movie()->create(['name' => 'First']);
    $second = Title::factory()->movie()->create(['name' => 'Second']);

    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $second->id, 'position' => 1]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $first->id, 'position' => 0]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/lists/{$mediaList->id}/items");

    $response->assertOk();
    expect($response->json('data.*.title.id'))->toBe([$first->id, $second->id]);
    expect($response->json('data.0'))->toHaveKeys(['item_id', 'position', 'title']);
    expect($response->json('data.0.title'))->toHaveKeys(['id', 'name', 'type', 'poster_url', 'backdrop_url', 'release_date', 'status']);
});

test('list items can be filtered by show status', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();
    $ongoing = Title::factory()->show()->create(['status' => 'Returning Series']);
    $ended = Title::factory()->show()->create(['status' => 'Ended']);
    $movie = Title::factory()->movie()->create();

    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $ongoing->id, 'position' => 0]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $ended->id, 'position' => 1]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $movie->id, 'position' => 2]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/lists/{$mediaList->id}/items?status=ongoing");

    $response->assertOk();
    expect($response->json('data.*.title.id'))->toBe([$ongoing->id]);
});

test('list items can be sorted', function (string $sort, array $expected) {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();
    $old = Title::factory()->movie()->create(['name' => 'Old', 'release_date' => '2000-01-01']);
    $new = Title::factory()->movie()->create(['name' => 'New', 'release_date' => '2020-01-01']);
    Rating::factory()->create(['rateable_id' => $old->id, 'score' => 9]);

    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $old->id, 'position' => 0]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $new->id, 'position' => 1]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/lists/{$mediaList->id}/items?sort={$sort}");

    $response->assertOk();
    expect($response->json('data.*.title.name'))->toBe($expected);
})->with([
    'recently released' => ['recently-released', ['New', 'Old']],
    'oldest release' => ['oldest-release', ['Old', 'New']],
    'title' => ['title', ['New', 'Old']],
    'my rating' => ['my-rating', ['Old', 'New']],
]);

test('list items can be filtered by type and release', function (string $query, string $expected) {
    $user = User::factory()->create();
    $this->travelTo('2026-06-15');
    $mediaList = MediaList::factory()->create();
    $released = Title::factory()->movie()->create(['name' => 'Released Movie', 'release_date' => '2020-01-01']);
    $upcoming = Title::factory()->movie()->create(['name' => 'Upcoming Movie', 'release_date' => '2027-01-01']);
    $show = Title::factory()->show()->create(['name' => 'A Show', 'release_date' => '2010-01-01']);

    foreach ([$released, $upcoming, $show] as $position => $title) {
        MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id, 'position' => $position]);
    }

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/lists/{$mediaList->id}/items?{$query}");

    $response->assertOk();
    expect($response->json('data.*.title.name'))->toContain($expected);
    expect($response->json('data'))->toHaveCount(str_contains($query, 'type=show') ? 1 : (str_contains($query, 'release=') ? 1 : 3));
})->with([
    'type' => ['type=show', 'A Show'],
    'released' => ['release=released&type=movie', 'Released Movie'],
    'upcoming' => ['release=upcoming', 'Upcoming Movie'],
]);

test('list items can be filtered to unwatched titles', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();
    $watched = Title::factory()->movie()->create(['name' => 'Watched']);
    $unwatched = Title::factory()->movie()->create(['name' => 'Unwatched']);
    Play::factory()->create(['playable_id' => $watched->id]);

    $finishedShow = Title::factory()->show()->create(['name' => 'Finished Show', 'in_production' => false]);
    $episode = Episode::factory()->aired()->for(Season::factory()->for($finishedShow)->create(['season_number' => 1]))->create();
    Play::factory()->create(['playable_type' => 'episode', 'playable_id' => $episode->id]);

    foreach ([$watched, $unwatched, $finishedShow] as $position => $title) {
        MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id, 'position' => $position]);
    }

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/lists/{$mediaList->id}/items?unwatched=1");

    $response->assertOk();
    expect($response->json('data.*.title.name'))->toBe(['Unwatched']);
});

test('list item sort and filters combine', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();
    $b = Title::factory()->show()->create(['name' => 'B Show', 'status' => 'Ended']);
    $a = Title::factory()->show()->create(['name' => 'A Show', 'status' => 'Ended']);
    $ongoing = Title::factory()->show()->create(['name' => 'C Show', 'status' => 'Returning Series']);
    $movie = Title::factory()->movie()->create(['name' => 'A Movie']);

    foreach ([$b, $a, $ongoing, $movie] as $position => $title) {
        MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id, 'position' => $position]);
    }

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/lists/{$mediaList->id}/items?type=show&status=ended&sort=title");

    $response->assertOk();
    expect($response->json('data.*.title.name'))->toBe(['A Show', 'B Show']);
});

test('list items reject invalid sort and filter params', function (string $query, string $field) {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/lists/{$mediaList->id}/items?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    ['sort=bogus', 'sort'],
    ['type=bogus', 'type'],
    ['status=bogus', 'status'],
    ['release=bogus', 'release'],
    ['unwatched=maybe', 'unwatched'],
]);

test('a title can be added to a list', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();
    $title = Title::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson("/api/v1/lists/{$mediaList->id}/items", ['title_id' => $title->id])
        ->assertCreated();

    expect($mediaList->items()->where('title_id', $title->id)->exists())->toBeTrue();
});

test('adding a title already in the list returns a conflict', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();
    $title = Title::factory()->create();
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id]);

    $this->actingAs($user, 'sanctum')->postJson("/api/v1/lists/{$mediaList->id}/items", ['title_id' => $title->id])
        ->assertStatus(409);
});

test('adding an item requires a valid title id', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson("/api/v1/lists/{$mediaList->id}/items", ['title_id' => 999999])
        ->assertUnprocessable()->assertJsonValidationErrors('title_id');
});

test('a title can be removed from a list', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();
    $title = Title::factory()->create();
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id]);

    $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/lists/{$mediaList->id}/items/{$title->id}")
        ->assertNoContent();

    expect($mediaList->items()->where('title_id', $title->id)->exists())->toBeFalse();
});

test('a list item can be reordered', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();
    $first = Title::factory()->create();
    $second = Title::factory()->create();
    $third = Title::factory()->create();

    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $first->id, 'position' => 0]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $second->id, 'position' => 1]);
    MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $third->id, 'position' => 2]);

    $this->actingAs($user, 'sanctum')
        ->putJson("/api/v1/lists/{$mediaList->id}/items/{$third->id}/position", ['position' => 0])
        ->assertNoContent();

    $order = $mediaList->items()->orderBy('position')->pluck('title_id')->all();

    expect($order)->toBe([$third->id, $first->id, $second->id]);
});

test('list items can be filtered by streaming service in the current region', function () {
    $user = User::factory()->create();
    config(['services.tmdb.region' => 'US']);
    $mediaList = MediaList::factory()->create();
    $onNetflix = Title::factory()->movie()->create(['name' => 'On Netflix']);
    $onHulu = Title::factory()->movie()->create(['name' => 'On Hulu']);
    $netflixElsewhere = Title::factory()->movie()->create(['name' => 'Netflix In Canada']);

    foreach ([$onNetflix, $onHulu, $netflixElsewhere] as $position => $title) {
        MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id, 'position' => $position]);
    }

    $netflix = WatchProvider::factory()->create();
    $hulu = WatchProvider::factory()->create();
    $onNetflix->watchProviders()->attach($netflix->id, ['type' => 'flatrate', 'region' => 'US']);
    $onHulu->watchProviders()->attach($hulu->id, ['type' => 'flatrate', 'region' => 'US']);
    $netflixElsewhere->watchProviders()->attach($netflix->id, ['type' => 'flatrate', 'region' => 'CA']);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/lists/{$mediaList->id}/items?service={$netflix->id}");

    $response->assertOk();
    expect($response->json('data.*.title.name'))->toBe(['On Netflix']);
});

test('list items can be filtered by network', function () {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();
    $onHbo = Title::factory()->show()->create(['name' => 'On HBO']);
    $onAmc = Title::factory()->show()->create(['name' => 'On AMC']);

    foreach ([$onHbo, $onAmc] as $position => $title) {
        MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id, 'position' => $position]);
    }

    $hbo = Network::factory()->create();
    $amc = Network::factory()->create();
    $onHbo->networks()->attach($hbo->id, ['position' => 0]);
    $onAmc->networks()->attach($amc->id, ['position' => 0]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/lists/{$mediaList->id}/items?network={$hbo->id}");

    $response->assertOk();
    expect($response->json('data.*.title.name'))->toBe(['On HBO']);
});

test('the service and network filters must be existing ids', function (string $query, string $field) {
    $user = User::factory()->create();
    $mediaList = MediaList::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/lists/{$mediaList->id}/items?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'service not an integer' => ['service=netflix', 'service'],
    'service unknown' => ['service=99999', 'service'],
    'network not an integer' => ['network=hbo', 'network'],
    'network unknown' => ['network=99999', 'network'],
]);

test('the service filter is ignored when show where to watch is off', function () {
    $user = User::factory()->create();
    config(['services.tmdb.region' => 'US']);
    app(IntegrationSettings::class)->set('tmdb.show_watch_providers', false);
    $mediaList = MediaList::factory()->create();
    $onNetflix = Title::factory()->movie()->create(['name' => 'On Netflix']);
    $onHulu = Title::factory()->movie()->create(['name' => 'On Hulu']);

    foreach ([$onNetflix, $onHulu] as $position => $title) {
        MediaListItem::factory()->create(['media_list_id' => $mediaList->id, 'title_id' => $title->id, 'position' => $position]);
    }

    $netflix = WatchProvider::factory()->create();
    $onNetflix->watchProviders()->attach($netflix->id, ['type' => 'flatrate', 'region' => 'US']);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/lists/{$mediaList->id}/items?service={$netflix->id}");

    $response->assertOk();
    expect($response->json('data.*.title.name'))->toBe(['On Netflix', 'On Hulu']);
});
