<?php

use App\Models\Rating;
use App\Models\Title;
use App\Models\User;
use App\Services\Stats\StatsCacheVersion;

test('the rating endpoint requires authentication', function () {
    $title = Title::factory()->movie()->create();

    $this->getJson("/api/v1/titles/{$title->id}/rating")->assertUnauthorized();
    $this->putJson("/api/v1/titles/{$title->id}/rating", ['score' => 8])->assertUnauthorized();
    $this->deleteJson("/api/v1/titles/{$title->id}/rating")->assertUnauthorized();
});

test('getting a rating returns null when there is none', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/titles/{$title->id}/rating");

    $response->assertOk()->assertJson(['rating' => null]);
});

test('getting a rating returns the contract shape', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create([
        'score' => 9,
        'review' => 'Great watch.',
        'review_spoilers' => false,
        'reviewed_at' => '2026-05-01 00:00:00',
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/titles/{$title->id}/rating");

    $response->assertOk()->assertJson([
        'rating' => [
            'score' => 9,
            'review' => 'Great watch.',
            'review_spoilers' => false,
        ],
    ])->assertJsonStructure(['rating' => ['score', 'review', 'review_spoilers', 'reviewed_at']]);
});

test('setting a score creates a rating', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/titles/{$title->id}/rating", ['score' => 7]);

    $response->assertOk()->assertJson(['rating' => ['score' => 7]]);
    expect($title->rating()->first()->score)->toBe(7);
});

test('an out-of-range score is rejected with a 422', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/titles/{$title->id}/rating", ['score' => 11]);

    $response->assertStatus(422)->assertJsonValidationErrors('score');
});

test('omitting the score clears it, deleting the rating when there is no review', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create(['score' => 8, 'review' => null]);

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/titles/{$title->id}/rating", []);

    $response->assertOk()->assertJson(['rating' => null]);
    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->exists())->toBeFalse();
});

test('omitting the score clears it while keeping an existing review', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();
    $rating = Rating::factory()->for($title, 'rateable')->create(['score' => 8, 'review' => 'Loved it.']);

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/titles/{$title->id}/rating", []);

    $response->assertOk()->assertJson(['rating' => ['score' => null, 'review' => 'Loved it.']]);
    expect($rating->refresh())->score->toBeNull()->review->toBe('Loved it.');
});

test('deleting a rating removes the score only', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();
    $rating = Rating::factory()->for($title, 'rateable')->create(['score' => 8, 'review' => 'Loved it.']);

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/titles/{$title->id}/rating");

    $response->assertNoContent();
    expect($rating->refresh())->score->toBeNull()->review->toBe('Loved it.');
});

test('deleting a rating with no review removes the row entirely', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create(['score' => 8, 'review' => null]);

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/titles/{$title->id}/rating");

    $response->assertNoContent();
    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->exists())->toBeFalse();
});

test('an unknown title returns a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/titles/999999/rating')->assertNotFound();
});

test('setting a score bumps the stats cache version', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();
    $before = app(StatsCacheVersion::class)->current();

    $this->actingAs($user, 'sanctum')->putJson("/api/v1/titles/{$title->id}/rating", ['score' => 7]);

    expect(app(StatsCacheVersion::class)->current())->toBeGreaterThan($before);
});
