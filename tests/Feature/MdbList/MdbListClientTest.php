<?php

use App\Services\MdbList\MdbListClient;
use App\Services\MdbList\MdbListException;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('test connection is false when mdblist is not configured', function () {
    expect(app(MdbListClient::class)->testConnection())->toBeFalse();

    Http::assertNothingSent();
});

test('test connection validates the configured api key against mdblist', function () {
    app(IntegrationSettings::class)->set('mdblist.api_key', 'test-key');

    Http::fake([
        'https://api.mdblist.com/user*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/mdblist/user.json')), true)),
    ]);

    expect(app(MdbListClient::class)->testConnection())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.mdblist.com/user?apikey=test-key');
});

test('test connection is false when mdblist reports an invalid key', function () {
    app(IntegrationSettings::class)->set('mdblist.api_key', 'bad-key');

    Http::fake([
        'https://api.mdblist.com/user*' => Http::response(['error' => 'Invalid API key'], 401),
    ]);

    expect(app(MdbListClient::class)->testConnection())->toBeFalse();
});

test('lookup throws when mdblist is not configured', function () {
    app(MdbListClient::class)->lookup('imdb', 'movie', 'tt0133093');
})->throws(MdbListException::class);

test('lookup returns the decoded response for a configured key', function () {
    app(IntegrationSettings::class)->set('mdblist.api_key', 'test-key');

    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/mdblist/movie_603.json')), true);

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt0133093/*' => Http::response($fixture),
    ]);

    $response = app(MdbListClient::class)->lookup('imdb', 'movie', 'tt0133093');

    expect($response['title'])->toBe('The Matrix')
        ->and($response['ratings'])->toBeArray();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.mdblist.com/imdb/movie/tt0133093/?apikey=test-key');
});

test('lookup throws when mdblist responds with an error', function () {
    app(IntegrationSettings::class)->set('mdblist.api_key', 'test-key');

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt0133093/*' => Http::response(['error' => 'Not Found'], 404),
    ]);

    app(MdbListClient::class)->lookup('imdb', 'movie', 'tt0133093');
})->throws(MdbListException::class);

test('lookup throws without retrying when mdblist rate limits the request', function () {
    app(IntegrationSettings::class)->set('mdblist.api_key', 'test-key');

    Http::fake([
        'https://api.mdblist.com/imdb/movie/tt0133093/*' => Http::response(['error' => 'Too Many Requests'], 429),
    ]);

    try {
        app(MdbListClient::class)->lookup('imdb', 'movie', 'tt0133093');
    } catch (MdbListException) {
        // expected
    }

    Http::assertSentCount(1);
});
