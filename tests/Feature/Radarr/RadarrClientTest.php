<?php

use App\Services\Radarr\RadarrClient;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('testConnection returns false when not configured', function () {
    expect(app(RadarrClient::class)->testConnection())->toBeFalse();
});

test('testConnection returns true on a successful status check', function () {
    app(IntegrationSettings::class)->setMany([
        'radarr.url' => 'https://radarr.test',
        'radarr.api_key' => 'secret-key',
    ]);

    Http::fake(['radarr.test/api/v3/system/status*' => Http::response(['version' => '5.0'])]);

    expect(app(RadarrClient::class)->testConnection())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'secret-key'));
});

test('movieByTmdbId returns the matching movie', function () {
    app(IntegrationSettings::class)->setMany([
        'radarr.url' => 'https://radarr.test',
        'radarr.api_key' => 'secret-key',
    ]);

    Http::fake(['radarr.test/api/v3/movie*' => Http::response([
        ['id' => 9, 'tmdbId' => 603, 'hasFile' => true],
    ])]);

    $movie = app(RadarrClient::class)->movieByTmdbId(603);

    expect($movie['id'])->toBe(9);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'tmdbId=603'));
});

test('movieByTmdbId returns null when not configured', function () {
    expect(app(RadarrClient::class)->movieByTmdbId(1))->toBeNull();
});
