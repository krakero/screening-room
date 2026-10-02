<?php

use App\Enums\LibraryState;
use App\Enums\SeerrRequestStatus;
use App\Events\TitleBecameAvailable;
use App\Events\TitleRequestDeclined;
use App\Models\LibraryStatus;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    app(IntegrationSettings::class)->setMany([
        'sonarr.url' => 'https://sonarr.test',
        'sonarr.api_key' => 'sonarr-key',
        'radarr.url' => 'https://radarr.test',
        'radarr.api_key' => 'radarr-key',
    ]);
});

test('it marks a show available once sonarr reports full episode coverage', function () {
    Event::fake([TitleBecameAvailable::class]);

    $title = Title::factory()->show()->create(['tvdb_id' => 121361]);
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Downloading]);

    Http::fake(['sonarr.test/api/v3/series*' => Http::response([
        ['id' => 5, 'tvdbId' => 121361, 'statistics' => ['percentOfEpisodes' => 100]],
    ])]);

    $this->artisan('library:reconcile')->assertSuccessful();

    expect(LibraryStatus::where('title_id', $title->id)->sole()->state)->toBe(LibraryState::Available);

    Event::assertDispatched(TitleBecameAvailable::class);
});

test('it marks a movie available once radarr reports a downloaded file', function () {
    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Downloading]);

    Http::fake(['radarr.test/api/v3/movie*' => Http::response([
        ['id' => 9, 'tmdbId' => 603, 'hasFile' => true],
    ])]);

    $this->artisan('library:reconcile')->assertSuccessful();

    expect(LibraryStatus::where('title_id', $title->id)->sole()->state)->toBe(LibraryState::Available);
});

test('it leaves available statuses alone', function () {
    Http::fake();

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Available]);

    $this->artisan('library:reconcile')->assertSuccessful();

    Http::assertNothingSent();
});

test('it refreshes an open seerr request from pending approval to approved', function () {
    app(IntegrationSettings::class)->setMany([
        'seerr.url' => 'https://seerr.test',
        'seerr.api_key' => 'seerr-key',
    ]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    $status = LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Requested,
        'seerr_request_id' => 77,
        'seerr_status' => SeerrRequestStatus::PendingApproval,
    ]);

    Http::fake([
        'seerr.test/api/v1/movie/603' => Http::response([
            'id' => 603,
            'mediaInfo' => ['status' => 3, 'requests' => [['id' => 77, 'status' => 2]]],
        ]),
        'radarr.test/api/v3/movie*' => Http::response([]),
    ]);

    $this->artisan('library:reconcile')->assertSuccessful();

    expect($status->fresh()->seerr_status)->toBe(SeerrRequestStatus::Approved)
        ->and($status->fresh()->state)->toBe(LibraryState::Requested);
});

test('it marks an open seerr request available once seerr reports it available', function () {
    Event::fake([TitleBecameAvailable::class]);

    app(IntegrationSettings::class)->setMany([
        'seerr.url' => 'https://seerr.test',
        'seerr.api_key' => 'seerr-key',
    ]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Requested,
        'seerr_request_id' => 77,
    ]);

    Http::fake(['seerr.test/api/v1/movie/603' => Http::response([
        'id' => 603,
        'mediaInfo' => ['status' => 5, 'requests' => [['id' => 77, 'status' => 2]]],
    ])]);

    $this->artisan('library:reconcile')->assertSuccessful();

    expect(LibraryStatus::where('title_id', $title->id)->sole()->state)->toBe(LibraryState::Available);

    Event::assertDispatched(TitleBecameAvailable::class);
});

test('it marks an open seerr request declined and notifies once', function () {
    Event::fake([TitleRequestDeclined::class]);

    app(IntegrationSettings::class)->setMany([
        'seerr.url' => 'https://seerr.test',
        'seerr.api_key' => 'seerr-key',
    ]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Requested,
        'seerr_request_id' => 77,
    ]);

    Http::fake(['seerr.test/api/v1/movie/603' => Http::response([
        'id' => 603,
        'mediaInfo' => ['status' => 1, 'requests' => [['id' => 77, 'status' => 3]]],
    ])]);

    $this->artisan('library:reconcile')->assertSuccessful();

    expect(LibraryStatus::where('title_id', $title->id)->sole()->state)->toBe(LibraryState::Declined);

    Event::assertDispatchedTimes(TitleRequestDeclined::class, 1);
});
