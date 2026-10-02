<?php

use App\Enums\TorrentState;
use App\Services\Qbittorrent\QbittorrentClient;
use App\Services\Qbittorrent\QbittorrentException;
use App\Support\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::forget('qbittorrent:sid');
});

function configureQbittorrent(bool $withCredentials = true): void
{
    app(IntegrationSettings::class)->setMany(array_filter([
        'qbittorrent.url' => 'http://qbit.test:8080/',
        'qbittorrent.username' => $withCredentials ? 'admin' : null,
        'qbittorrent.password' => $withCredentials ? 'secret' : null,
    ]));
}

function loginResponse(string $sid = 'abc123')
{
    return Http::response('Ok.', 200, ['Set-Cookie' => "SID={$sid}; HttpOnly; path=/"]);
}

test('it reports configuration and the normalised web UI url', function () {
    expect(app(QbittorrentClient::class)->configured())->toBeFalse()
        ->and(app(QbittorrentClient::class)->webUiUrl())->toBeNull()
        ->and(app(QbittorrentClient::class)->testConnection())->toBeFalse();

    configureQbittorrent();

    expect(app(QbittorrentClient::class)->configured())->toBeTrue()
        ->and(app(QbittorrentClient::class)->webUiUrl())->toBe('http://qbit.test:8080');
});

test('login caches the SID and sends it as a cookie', function () {
    configureQbittorrent();

    Http::fake([
        'qbit.test:8080/api/v2/auth/login' => loginResponse('abc123'),
        'qbit.test:8080/api/v2/app/version' => Http::response('v5.0.0'),
    ]);

    $client = app(QbittorrentClient::class);

    expect($client->version())->toBe('v5.0.0')
        ->and($client->version())->toBe('v5.0.0')
        ->and(Cache::get('qbittorrent:sid'))->toBe('abc123');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/auth/login')
        && $request['username'] === 'admin'
        && $request['password'] === 'secret'
        && $request->hasHeader('Referer', 'http://qbit.test:8080'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/app/version')
        && str_contains($request->header('Cookie')[0] ?? '', 'SID=abc123'));
});

test('no username skips login entirely', function () {
    configureQbittorrent(withCredentials: false);

    Http::fake(['qbit.test:8080/api/v2/app/version' => Http::response('v5.0.0')]);

    expect(app(QbittorrentClient::class)->version())->toBe('v5.0.0');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'auth/login'));
});

test('a 403 clears the SID, logs in again once and retries', function () {
    configureQbittorrent();
    Cache::put('qbittorrent:sid', 'stale', now()->addMinutes(10));

    Http::fake([
        'qbit.test:8080/api/v2/auth/login' => loginResponse('fresh'),
        'qbit.test:8080/api/v2/app/version' => Http::sequence()
            ->push('Forbidden', 403)
            ->push('v5.0.0'),
    ]);

    expect(app(QbittorrentClient::class)->version())->toBe('v5.0.0')
        ->and(Cache::get('qbittorrent:sid'))->toBe('fresh');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/auth/login'));
});

test('a persistent 403 throws after a single retry', function () {
    configureQbittorrent();

    Http::fake([
        'qbit.test:8080/api/v2/auth/login' => loginResponse(),
        'qbit.test:8080/api/v2/app/version' => Http::response('Forbidden', 403),
    ]);

    expect(fn () => app(QbittorrentClient::class)->version())->toThrow(QbittorrentException::class);

    Http::assertSentCount(4);
});

test('bad credentials throw', function () {
    configureQbittorrent();

    Http::fake(['qbit.test:8080/api/v2/auth/login' => Http::response('Fails.', 200)]);

    expect(fn () => app(QbittorrentClient::class)->version())
        ->toThrow(QbittorrentException::class, 'rejected the username or password');

    expect(app(QbittorrentClient::class)->testConnection())->toBeFalse();
});

test('a connection error throws a QbittorrentException', function () {
    configureQbittorrent(withCredentials: false);

    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => app(QbittorrentClient::class)->version())
        ->toThrow(QbittorrentException::class, 'Connection refused');
});

test('a non-2xx response throws', function () {
    configureQbittorrent(withCredentials: false);

    Http::fake(['qbit.test:8080/api/v2/app/version' => Http::response('boom', 500)]);

    expect(fn () => app(QbittorrentClient::class)->version())
        ->toThrow(QbittorrentException::class, 'status 500');
});

test('torrents maps rows and sorts downloading first then newest added', function () {
    configureQbittorrent(withCredentials: false);

    Http::fake(['qbit.test:8080/api/v2/torrents/info' => Http::response([
        ['hash' => 'old-seed', 'name' => 'Old Seed', 'state' => 'uploading', 'added_on' => 1000, 'size' => 10, 'progress' => 1, 'eta' => 8640000, 'category' => ''],
        ['hash' => 'new-seed', 'name' => 'New Seed', 'state' => 'stalledUP', 'added_on' => 3000, 'size' => 10, 'progress' => 1, 'eta' => 8640000, 'category' => 'tv'],
        ['hash' => 'old-dl', 'name' => 'Old Download', 'state' => 'downloading', 'added_on' => 2000, 'total_size' => 500, 'downloaded' => 250, 'progress' => 0.505, 'dlspeed' => 1024, 'upspeed' => 10, 'eta' => 120, 'category' => 'movies', 'num_seeds' => 4, 'num_leechs' => 2, 'ratio' => 0.5],
    ])]);

    $torrents = app(QbittorrentClient::class)->torrents();

    expect($torrents->pluck('hash')->all())->toBe(['old-dl', 'new-seed', 'old-seed']);

    $download = $torrents->first();

    expect($download->state)->toBe(TorrentState::Downloading)
        ->and($download->size)->toBe(500)
        ->and($download->downloaded)->toBe(250)
        ->and($download->percent())->toBe(50)
        ->and($download->dlspeed)->toBe(1024)
        ->and($download->eta)->toBe(120)
        ->and($download->category)->toBe('movies')
        ->and($download->numSeeds)->toBe(4)
        ->and($download->numLeechs)->toBe(2)
        ->and($download->ratio)->toBe(0.5)
        ->and($download->addedOn->timestamp)->toBe(2000);
});

test('an infinite eta becomes null and an empty category becomes null', function () {
    configureQbittorrent(withCredentials: false);

    Http::fake(['qbit.test:8080/api/v2/torrents/info' => Http::response([
        ['hash' => 'a', 'name' => 'A', 'state' => 'stalledDL', 'eta' => 8640000, 'category' => ''],
    ])]);

    $torrent = app(QbittorrentClient::class)->torrents()->first();

    expect($torrent->eta)->toBeNull()
        ->and($torrent->category)->toBeNull()
        ->and($torrent->state)->toBe(TorrentState::Stalled);
});

test('testConnection is true when the version endpoint answers', function () {
    configureQbittorrent(withCredentials: false);

    Http::fake(['qbit.test:8080/api/v2/app/version' => Http::response('v5.0.0')]);

    expect(app(QbittorrentClient::class)->testConnection())->toBeTrue();
});
