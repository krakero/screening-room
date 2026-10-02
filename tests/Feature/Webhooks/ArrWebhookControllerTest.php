<?php

use App\Enums\LibraryState;
use App\Enums\SeerrRequestStatus;
use App\Events\TitleBecameAvailable;
use App\Events\TitleRequestDeclined;
use App\Events\TitleRequestFailed;
use App\Models\LibraryStatus;
use App\Models\Title;
use App\Models\WebhookEvent;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    app(IntegrationSettings::class)->set('webhooks.arr_secret', 'test-secret');
});

test('a wrong secret is rejected with a 404', function () {
    $this->postJson('/webhooks/arr/wrong-secret', [])->assertNotFound();

    expect(WebhookEvent::count())->toBe(0);
});

test('every inbound webhook is logged', function () {
    $this->postJson('/webhooks/arr/test-secret', ['notification_type' => 'TEST_NOTIFICATION'])->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::first())
        ->source->toBe('seerr')
        ->processed_at->not->toBeNull();
});

test('seerr MEDIA_AVAILABLE marks the title available and fires the event', function () {
    Event::fake([TitleBecameAvailable::class]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    $this->postJson('/webhooks/arr/test-secret', [
        'notification_type' => 'MEDIA_AVAILABLE',
        'media' => ['media_type' => 'movie', 'tmdbId' => 603],
        'request' => ['request_id' => 42],
    ])->assertNoContent();

    $status = LibraryStatus::where('title_id', $title->id)->sole();

    expect($status->state)->toBe(LibraryState::Available)
        ->and($status->seerr_request_id)->toBe(42);

    Event::assertDispatched(TitleBecameAvailable::class, fn ($event) => $event->title->is($title));
});

test('seerr MEDIA_PENDING marks the title requested and pending approval', function () {
    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);

    $this->postJson('/webhooks/arr/test-secret', [
        'notification_type' => 'MEDIA_PENDING',
        'media' => ['media_type' => 'tv', 'tmdbId' => 1396],
        'request' => ['request_id' => 55],
    ])->assertNoContent();

    $status = LibraryStatus::where('title_id', $title->id)->sole();

    expect($status->state)->toBe(LibraryState::Requested)
        ->and($status->seerr_status)->toBe(SeerrRequestStatus::PendingApproval)
        ->and($status->seerr_request_id)->toBe(55);
});

test('seerr MEDIA_APPROVED marks the title approved', function () {
    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);

    $this->postJson('/webhooks/arr/test-secret', [
        'notification_type' => 'MEDIA_APPROVED',
        'media' => ['media_type' => 'tv', 'tmdbId' => 1396],
    ])->assertNoContent();

    $status = LibraryStatus::where('title_id', $title->id)->sole();

    expect($status->state)->toBe(LibraryState::Requested)
        ->and($status->seerr_status)->toBe(SeerrRequestStatus::Approved);
});

test('seerr MEDIA_DECLINED marks the title declined and notifies once', function () {
    Event::fake([TitleRequestDeclined::class]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Requested]);

    $this->postJson('/webhooks/arr/test-secret', [
        'notification_type' => 'MEDIA_DECLINED',
        'media' => ['media_type' => 'movie', 'tmdbId' => 603],
    ])->assertNoContent();

    expect(LibraryStatus::where('title_id', $title->id)->sole()->state)->toBe(LibraryState::Declined);

    Event::assertDispatched(TitleRequestDeclined::class, fn ($event) => $event->title->is($title));

    // A repeat delivery of the same webhook must not notify twice.
    $this->postJson('/webhooks/arr/test-secret', [
        'notification_type' => 'MEDIA_DECLINED',
        'media' => ['media_type' => 'movie', 'tmdbId' => 603],
    ])->assertNoContent();

    Event::assertDispatchedTimes(TitleRequestDeclined::class, 1);
});

test('seerr MEDIA_FAILED marks the title failed and notifies', function () {
    Event::fake([TitleRequestFailed::class]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Requested]);

    $this->postJson('/webhooks/arr/test-secret', [
        'notification_type' => 'MEDIA_FAILED',
        'media' => ['media_type' => 'movie', 'tmdbId' => 603],
    ])->assertNoContent();

    expect(LibraryStatus::where('title_id', $title->id)->sole()->state)->toBe(LibraryState::Failed);

    Event::assertDispatched(TitleRequestFailed::class, fn ($event) => $event->title->is($title));
});

test('sonarr Grab marks the show downloading', function () {
    $title = Title::factory()->show()->create(['tvdb_id' => 121361]);

    $this->postJson('/webhooks/arr/test-secret', [
        'eventType' => 'Grab',
        'series' => ['id' => 5, 'tvdbId' => 121361],
    ])->assertNoContent();

    $status = LibraryStatus::where('title_id', $title->id)->sole();

    expect($status->state)->toBe(LibraryState::Downloading)
        ->and($status->sonarr_id)->toBe(5);
});

test('sonarr Download marks the show available and fires the event', function () {
    Event::fake([TitleBecameAvailable::class]);

    $title = Title::factory()->show()->create(['tvdb_id' => 121361]);
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Downloading]);

    $this->postJson('/webhooks/arr/test-secret', [
        'eventType' => 'Download',
        'series' => ['id' => 5, 'tvdbId' => 121361],
    ])->assertNoContent();

    expect(LibraryStatus::where('title_id', $title->id)->sole()->state)->toBe(LibraryState::Available);

    Event::assertDispatched(TitleBecameAvailable::class);
});

test('sonarr Delete removes the library status', function () {
    $title = Title::factory()->show()->create(['tvdb_id' => 121361]);
    LibraryStatus::factory()->for($title)->create();

    $this->postJson('/webhooks/arr/test-secret', [
        'eventType' => 'Delete',
        'series' => ['id' => 5, 'tvdbId' => 121361],
    ])->assertNoContent();

    expect(LibraryStatus::where('title_id', $title->id)->exists())->toBeFalse();
});

test('radarr Grab marks the movie downloading', function () {
    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    $this->postJson('/webhooks/arr/test-secret', [
        'eventType' => 'Grab',
        'movie' => ['id' => 9, 'tmdbId' => 603],
    ])->assertNoContent();

    $status = LibraryStatus::where('title_id', $title->id)->sole();

    expect($status->state)->toBe(LibraryState::Downloading)
        ->and($status->radarr_id)->toBe(9);
});

test('radarr Download marks the movie available', function () {
    Event::fake([TitleBecameAvailable::class]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    $this->postJson('/webhooks/arr/test-secret', [
        'eventType' => 'Download',
        'movie' => ['id' => 9, 'tmdbId' => 603],
    ])->assertNoContent();

    expect(LibraryStatus::where('title_id', $title->id)->sole()->state)->toBe(LibraryState::Available);

    Event::assertDispatched(TitleBecameAvailable::class);
});

test('an unmatched title is ignored without error', function () {
    $this->postJson('/webhooks/arr/test-secret', [
        'eventType' => 'Grab',
        'movie' => ['id' => 9, 'tmdbId' => 999999],
    ])->assertNoContent();

    expect(LibraryStatus::count())->toBe(0);
});
