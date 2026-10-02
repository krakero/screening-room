<?php

use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('watching a movie requires authentication', function () {
    $title = Title::factory()->movie()->create();

    $response = $this->postJson("/api/v1/titles/{$title->id}/watch");

    $response->assertUnauthorized();
});

test('watching a movie logs a play', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/watch");

    $response->assertCreated()->assertJsonStructure(['play' => ['id', 'watched_at', 'source']]);

    expect($title->plays()->count())->toBe(1);
});

test('watching a movie rejects a show title', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/watch");

    $response->assertUnprocessable()->assertJsonValidationErrors('title');
});

test('marking a show watched requires authentication', function () {
    $title = Title::factory()->show()->create();

    $response = $this->postJson("/api/v1/titles/{$title->id}/watch-show");

    $response->assertUnauthorized();
});

test('marking a show watched logs plays for every aired unwatched episode and returns the count', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);

    $watched = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($watched, 'playable')->create();

    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Episode::factory()->unaired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/watch-show");

    $response->assertOk()->assertJson(['marked_count' => 2]);
});

test('marking a show watched rejects a movie title', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/watch-show");

    $response->assertUnprocessable()->assertJsonValidationErrors('title');
});

test('previewing a show watch requires authentication', function () {
    $title = Title::factory()->show()->create();

    $response = $this->getJson("/api/v1/titles/{$title->id}/watch-show/preview");

    $response->assertUnauthorized();
});

test('previewing a show watch returns the aired unwatched count without marking anything', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);

    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/titles/{$title->id}/watch-show/preview");

    $response->assertOk()->assertJson(['aired_unwatched_count' => 2]);
    expect(Play::query()->count())->toBe(0);
});

test('previewing a season watch counts unwatched episodes with a single query, not one per episode', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create(['episodes_synced_at' => now()]);

    Episode::factory()->count(5)->aired()->for($season)->create();

    $this->actingAs($user, 'sanctum');

    DB::enableQueryLog();
    $response = $this->getJson("/api/v1/seasons/{$season->id}/watch/preview");
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk()->assertJson(['aired_unwatched_count' => 5]);
    expect($queryCount)->toBeLessThan(5);
});

test('marking a season watched requires authentication', function () {
    $season = Season::factory()->create();

    $response = $this->postJson("/api/v1/seasons/{$season->id}/watch");

    $response->assertUnauthorized();
});

test('marking a season watched logs plays for every aired unwatched episode and returns the count', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create();

    $watched = Episode::factory()->aired()->for($season)->create();
    Play::factory()->for($watched, 'playable')->create();

    Episode::factory()->aired()->for($season)->create();
    Episode::factory()->unaired()->for($season)->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/seasons/{$season->id}/watch");

    $response->assertOk()->assertJson(['marked_count' => 1]);
});

test('marking a season watched accepts a custom datetime', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create();
    Episode::factory()->aired()->for($season)->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/seasons/{$season->id}/watch", [
        'when' => 'custom',
        'datetime' => '2022-03-01T08:00:00Z',
    ]);

    $response->assertOk()->assertJson(['marked_count' => 1]);

    expect(Play::query()->sole()->watched_at->toIso8601ZuluString())->toBe('2022-03-01T08:00:00Z');
});

test('previewing a season watch requires authentication', function () {
    $season = Season::factory()->create();

    $response = $this->getJson("/api/v1/seasons/{$season->id}/watch/preview");

    $response->assertUnauthorized();
});

test('previewing a season watch returns the aired unwatched count without marking anything', function () {
    $user = User::factory()->create();
    $season = Season::factory()->create();

    Episode::factory()->aired()->for($season)->create();
    Episode::factory()->aired()->for($season)->create();
    Episode::factory()->unaired()->for($season)->create();

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/seasons/{$season->id}/watch/preview");

    $response->assertOk()->assertJson(['aired_unwatched_count' => 2]);
    expect(Play::query()->count())->toBe(0);
});
