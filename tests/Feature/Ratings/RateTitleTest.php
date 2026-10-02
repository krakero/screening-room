<?php

use App\Models\Episode;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Support\DisplayTimezone;
use Livewire\Livewire;

test('the star rating and review flyout render on a movie title page', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create(['score' => 7, 'review' => 'Solid rewatch.']);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Edit review');
    $response->assertSee('Solid rewatch.');
    $response->assertDontSee('@if');
});

test('the star rating and review flyout render on a show title page', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Add review');
    $response->assertDontSee('@if');
});

test('clicking a half-star sets a half-star score', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('setScore', 7);

    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->first())
        ->score->toBe(7);
});

test('clicking a full-star sets a whole-star score', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('setScore', 10);

    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->first())
        ->score->toBe(10);
});

test('clicking the current score again clears it', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create(['score' => 8, 'review' => null]);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('setScore', 8);

    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->exists())->toBeFalse();
});

test('clicking the current score again keeps the row when a review exists', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    $rating = Rating::factory()->for($title, 'rateable')->create(['score' => 8, 'review' => 'Loved it.']);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('setScore', 8);

    expect($rating->refresh())
        ->score->toBeNull()
        ->review->toBe('Loved it.');
});

test('setting a new score updates the existing rating', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create(['score' => 8]);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('setScore', 4);

    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->count())->toBe(1)
        ->and($title->rating()->first()->score)->toBe(4);
});

test('an out-of-range score is ignored', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('setScore', 11);

    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->exists())->toBeFalse();
});

test('a review can be saved on a title', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('openReviewFlyout')
        ->set('reviewText', 'Rewatch material.')
        ->set('reviewSpoilers', true)
        ->set('watchedOn', '2026-05-01')
        ->call('saveReview');

    $rating = $title->rating()->first();

    expect($rating->review)->toBe('Rewatch material.')
        ->and($rating->review_spoilers)->toBeTrue()
        ->and($rating->reviewed_at->toDateString())->toBe('2026-05-01');
});

test('saving an empty review deletes the rating when there is no score', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create(['score' => null, 'review' => 'Old review.']);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->set('reviewText', '   ')
        ->call('saveReview');

    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->exists())->toBeFalse();
});

test('saving an empty review keeps the rating when a score exists', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    $rating = Rating::factory()->for($title, 'rateable')->create(['score' => 8, 'review' => 'Old review.']);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->set('reviewText', '')
        ->call('saveReview');

    expect($rating->refresh())
        ->score->toBe(8)
        ->review->toBeNull();
});

test('a review longer than 5000 characters fails validation', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->set('reviewText', str_repeat('a', 5001))
        ->call('saveReview')
        ->assertHasErrors(['reviewText']);
});

test('deleting a review clears review fields but keeps the score', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    $rating = Rating::factory()->for($title, 'rateable')->create([
        'score' => 8,
        'review' => 'Old review.',
        'review_spoilers' => true,
        'reviewed_at' => now(),
    ]);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('deleteReview');

    expect($rating->refresh())
        ->score->toBe(8)
        ->review->toBeNull()
        ->review_spoilers->toBeFalse()
        ->reviewed_at->toBeNull();
});

test('deleting a review deletes the rating when there is no score', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create(['score' => null, 'review' => 'Old review.']);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('deleteReview');

    expect(Rating::query()->where('rateable_type', 'title')->where('rateable_id', $title->id)->exists())->toBeFalse();
});

test('opening the review flyout defaults the watched-on date to the latest movie play', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->movie()->create();
    Play::factory()->for($title, 'playable')->create(['watched_at' => '2026-04-10 12:00:00']);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('openReviewFlyout')
        ->assertSet('watchedOn', '2026-04-10');
});

test('opening the review flyout defaults the watched-on date to the latest episode play for a show', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create(['season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create(['watched_at' => '2026-06-15 08:00:00']);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('openReviewFlyout')
        ->assertSet('watchedOn', '2026-06-15');
});

test('opening the review flyout defaults the watched-on date to the stored reviewed_at when present', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->movie()->create();
    Rating::factory()->for($title, 'rateable')->create(['reviewed_at' => '2026-01-02 00:00:00']);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('openReviewFlyout')
        ->assertSet('watchedOn', DisplayTimezone::local('2026-01-02 00:00:00')->toDateString());
});
