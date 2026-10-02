<?php

use App\Models\Rating;
use App\Models\Title;
use App\Models\User;

test('the review endpoint requires authentication', function () {
    $title = Title::factory()->movie()->create();

    $this->putJson("/api/v1/titles/{$title->id}/rating/review", ['review' => 'Great.'])->assertUnauthorized();
    $this->deleteJson("/api/v1/titles/{$title->id}/rating/review")->assertUnauthorized();
});

test('a review can be saved', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/titles/{$title->id}/rating/review", [
        'review' => 'Rewatch material.',
        'spoilers' => true,
        'watched_on' => '2026-05-01',
    ]);

    $response->assertOk()->assertJson([
        'rating' => ['review' => 'Rewatch material.', 'review_spoilers' => true],
    ]);

    $rating = $title->rating()->first();
    expect($rating->review)->toBe('Rewatch material.')
        ->and($rating->review_spoilers)->toBeTrue()
        ->and($rating->reviewed_at->toDateString())->toBe('2026-05-01');
});

test('a review longer than 5000 characters is rejected', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/titles/{$title->id}/rating/review", [
        'review' => str_repeat('a', 5001),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('review');
});

test('saving a null review deletes the rating when there is no score', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create(['score' => null, 'review' => 'Old review.']);

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/titles/{$title->id}/rating/review", ['review' => null]);

    $response->assertOk()->assertJson(['rating' => null]);
    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->exists())->toBeFalse();
});

test('saving a null review keeps the rating when a score exists', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();
    $rating = Rating::factory()->for($title, 'rateable')->create(['score' => 8, 'review' => 'Old review.']);

    $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/titles/{$title->id}/rating/review", ['review' => null]);

    $response->assertOk()->assertJson(['rating' => ['score' => 8, 'review' => null]]);
    expect($rating->refresh())->score->toBe(8)->review->toBeNull();
});

test('deleting a review clears review fields but keeps the score', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();
    $rating = Rating::factory()->for($title, 'rateable')->create([
        'score' => 8,
        'review' => 'Old review.',
        'review_spoilers' => true,
        'reviewed_at' => now(),
    ]);

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/titles/{$title->id}/rating/review");

    $response->assertNoContent();
    expect($rating->refresh())
        ->score->toBe(8)
        ->review->toBeNull()
        ->review_spoilers->toBeFalse()
        ->reviewed_at->toBeNull();
});

test('deleting a review deletes the rating when there is no score', function () {
    $user = User::factory()->create();
    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create(['score' => null, 'review' => 'Old review.']);

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/titles/{$title->id}/rating/review");

    $response->assertNoContent();
    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->exists())->toBeFalse();
});

test('an unknown title returns a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->putJson('/api/v1/titles/999999/rating/review', ['review' => 'x'])->assertNotFound();
});
