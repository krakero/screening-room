<?php

use App\Enums\SeerrRequestStatus;
use App\Models\Title;
use App\Services\Seerr\SeerrClient;
use App\Services\Seerr\SeerrException;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function configureSeerr(): void
{
    app(IntegrationSettings::class)->setMany([
        'seerr.url' => 'https://seerr.test',
        'seerr.api_key' => 'secret-key',
    ]);
}

test('testConnection returns false when not configured', function () {
    expect(app(SeerrClient::class)->testConnection())->toBeFalse();
});

test('testConnection returns true on a successful status check', function () {
    configureSeerr();

    Http::fake(['seerr.test/api/v1/status' => Http::response(['version' => '1.0'])]);

    expect(app(SeerrClient::class)->testConnection())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'secret-key'));
});

test('requestMovie posts the tmdb id and returns the request id', function () {
    configureSeerr();

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    Http::fake(['seerr.test/api/v1/request' => Http::response(['id' => 42, 'status' => 1])]);

    $result = app(SeerrClient::class)->requestMovie($title);

    expect($result)->toBe(['request_id' => 42, 'seerr_status' => SeerrRequestStatus::PendingApproval]);

    Http::assertSent(fn ($request) => $request['mediaType'] === 'movie' && $request['mediaId'] === 603);
});

test('requestShow posts the tmdb id and chosen seasons', function () {
    configureSeerr();

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);

    Http::fake(['seerr.test/api/v1/request' => Http::response(['id' => 7, 'status' => 2])]);

    $result = app(SeerrClient::class)->requestShow($title, [1, 2]);

    expect($result)->toBe(['request_id' => 7, 'seerr_status' => SeerrRequestStatus::Approved]);

    Http::assertSent(fn ($request) => $request['mediaType'] === 'tv' && $request['mediaId'] === 1396 && $request['seasons'] === [1, 2]);
});

test('requesting without being configured throws', function () {
    $title = Title::factory()->movie()->create();

    app(SeerrClient::class)->requestMovie($title);
})->throws(SeerrException::class);

test('mediaStatus returns null when seerr has no record of the title', function () {
    configureSeerr();

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    Http::fake(['seerr.test/api/v1/movie/603' => Http::response(['id' => 603, 'mediaInfo' => null])]);

    expect(app(SeerrClient::class)->mediaStatus($title))->toBeNull();
});

test('mediaStatus reports an already-available movie', function () {
    configureSeerr();

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    Http::fake(['seerr.test/api/v1/movie/603' => Http::response([
        'id' => 603,
        'mediaInfo' => ['status' => 5, 'requests' => [['id' => 11, 'status' => 2]]],
    ])]);

    $result = app(SeerrClient::class)->mediaStatus($title);

    expect($result['available'])->toBeTrue()
        ->and($result['request_id'])->toBe(11)
        ->and($result['declined'])->toBeFalse();
});

test('mediaStatus reports an already-requested, not yet available show', function () {
    configureSeerr();

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);

    Http::fake(['seerr.test/api/v1/tv/1396' => Http::response([
        'id' => 1396,
        'mediaInfo' => ['status' => 2, 'requests' => [['id' => 22, 'status' => 1]]],
    ])]);

    $result = app(SeerrClient::class)->mediaStatus($title);

    expect($result['available'])->toBeFalse()
        ->and($result['request_id'])->toBe(22)
        ->and($result['seerr_status'])->toBe(SeerrRequestStatus::PendingApproval)
        ->and($result['declined'])->toBeFalse();
});

test('mediaStatus reports a declined request', function () {
    configureSeerr();

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    Http::fake(['seerr.test/api/v1/movie/603' => Http::response([
        'id' => 603,
        'mediaInfo' => ['status' => 1, 'requests' => [['id' => 33, 'status' => 3]]],
    ])]);

    $result = app(SeerrClient::class)->mediaStatus($title);

    expect($result['declined'])->toBeTrue()
        ->and($result['available'])->toBeFalse();
});

test('mediaStatus returns null when seerr is not configured', function () {
    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    expect(app(SeerrClient::class)->mediaStatus($title))->toBeNull();
});
