<?php

use App\Services\Sonarr\SonarrClient;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('testConnection returns false when not configured', function () {
    expect(app(SonarrClient::class)->testConnection())->toBeFalse();
});

test('testConnection returns true on a successful status check', function () {
    app(IntegrationSettings::class)->setMany([
        'sonarr.url' => 'https://sonarr.test',
        'sonarr.api_key' => 'secret-key',
    ]);

    Http::fake(['sonarr.test/api/v3/system/status*' => Http::response(['version' => '4.0'])]);

    expect(app(SonarrClient::class)->testConnection())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'secret-key'));
});

test('seriesByTvdbId returns the matching series', function () {
    app(IntegrationSettings::class)->setMany([
        'sonarr.url' => 'https://sonarr.test',
        'sonarr.api_key' => 'secret-key',
    ]);

    Http::fake(['sonarr.test/api/v3/series*' => Http::response([
        ['id' => 5, 'tvdbId' => 121361, 'statistics' => ['percentOfEpisodes' => 100]],
    ])]);

    $series = app(SonarrClient::class)->seriesByTvdbId(121361);

    expect($series['id'])->toBe(5);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'tvdbId=121361'));
});

test('seriesByTvdbId returns null when not configured', function () {
    expect(app(SonarrClient::class)->seriesByTvdbId(1))->toBeNull();
});
