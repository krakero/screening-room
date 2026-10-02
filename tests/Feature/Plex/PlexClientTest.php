<?php

use App\Services\Plex\PlexClient;
use App\Services\Plex\PlexException;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
        'plex.account_id' => '1',
    ]);

    Http::preventStrayRequests();
});

function plexFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/Fixtures/plex/{$name}.json")),
        true,
    );
}

test('testConnection returns true when the server responds successfully', function () {
    Http::fake([
        '*plex.local:32400/identity*' => Http::response(['MediaContainer' => []]),
    ]);

    expect(app(PlexClient::class)->testConnection())->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Plex-Token', 'plex-token'));
});

test('testConnection returns false when the server is unreachable', function () {
    Http::fake([
        '*plex.local:32400/identity*' => Http::response(null, 500),
    ]);

    expect(app(PlexClient::class)->testConnection())->toBeFalse();
});

test('recentHistory parses and filters entries newer than since', function () {
    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response(plexFixture('history_all')),
    ]);

    $entries = app(PlexClient::class)->recentHistory(Carbon::createFromTimestamp(1757999999));

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['rating_key'])->toBe('501')
        ->and($entries[0]['type'])->toBe('movie')
        ->and($entries[1]['rating_key'])->toBe('901')
        ->and($entries[1]['grandparent_rating_key'])->toBe('800')
        ->and($entries[1]['season_number'])->toBe(1)
        ->and($entries[1]['episode_number'])->toBe(1);
});

test('recentHistory omits the account filter when no account id is configured', function () {
    app(IntegrationSettings::class)->forget('plex.account_id');

    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response(plexFixture('history_all')),
    ]);

    app(PlexClient::class)->recentHistory(Carbon::createFromTimestamp(1757999999));

    Http::assertSent(fn ($request): bool => ! str_contains($request->url(), 'accountID'));
});

test('recentHistory excludes entries older than since', function () {
    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response(plexFixture('history_all')),
    ]);

    $entries = app(PlexClient::class)->recentHistory(Carbon::createFromTimestamp(1758000400));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['rating_key'])->toBe('901');
});

test('recentHistory pages through the full history until a page comes back short', function () {
    $page1 = plexFixture('history_all');
    $page1['MediaContainer']['Metadata'] = array_fill(0, 100, [
        'ratingKey' => '1',
        'type' => 'movie',
        'title' => 'Filler',
        'viewedAt' => Carbon::now()->timestamp,
        'accountID' => 1,
    ]);

    $page2 = plexFixture('history_all');
    $page2['MediaContainer']['Metadata'][0]['viewedAt'] = Carbon::now()->timestamp;
    $page2['MediaContainer']['Metadata'][1]['viewedAt'] = Carbon::now()->timestamp;

    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::sequence()
            ->push($page1)
            ->push($page2),
    ]);

    $entries = app(PlexClient::class)->recentHistory(Carbon::now()->subDay());

    expect($entries)->toHaveCount(102);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Container-Start', '0'));
    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Container-Start', '100'));
});

test('resolveAccountId returns a purely numeric setting as-is without calling the API', function () {
    expect(app(PlexClient::class)->resolveAccountId())->toBe('1');

    Http::assertNothingSent();
});

test('resolveAccountId resolves a username via /accounts and caches the numeric id', function () {
    app(IntegrationSettings::class)->set('plex.account_id', 'vmitchell85');

    Http::fake([
        '*plex.local:32400/accounts*' => Http::response([
            'MediaContainer' => [
                'Account' => [
                    ['id' => 1, 'name' => 'vmitchell85'],
                    ['id' => 2, 'name' => ''],
                    ['id' => 3, 'name' => 'guest'],
                ],
            ],
        ]),
    ]);

    expect(app(PlexClient::class)->resolveAccountId())->toBe('1');

    Http::assertSentCount(1);

    // Second call uses the cached id and doesn't hit /accounts again.
    expect(app(PlexClient::class)->resolveAccountId())->toBe('1');

    Http::assertSentCount(1);
});

test('resolveAccountId returns null when the username is not found', function () {
    app(IntegrationSettings::class)->set('plex.account_id', 'nobody');

    Http::fake([
        '*plex.local:32400/accounts*' => Http::response([
            'MediaContainer' => ['Account' => [['id' => 1, 'name' => 'vmitchell85']]],
        ]),
    ]);

    expect(app(PlexClient::class)->resolveAccountId())->toBeNull();
});

test('resolveAccountId returns null instead of throwing when the /accounts lookup fails', function () {
    app(IntegrationSettings::class)->set('plex.account_id', 'vmitchell85');

    Http::fake([
        '*plex.local:32400/accounts*' => Http::response(null, 500),
    ]);

    expect(app(PlexClient::class)->resolveAccountId())->toBeNull();
});

test('accounts skips blank-named system accounts', function () {
    Http::fake([
        '*plex.local:32400/accounts*' => Http::response([
            'MediaContainer' => [
                'Account' => [
                    ['id' => 1, 'name' => 'vmitchell85'],
                    ['id' => 2, 'name' => ''],
                ],
            ],
        ]),
    ]);

    expect(app(PlexClient::class)->accounts())->toBe([
        ['id' => 1, 'name' => 'vmitchell85'],
    ]);
});

test('metadataGuids parses the Guid array for a movie', function () {
    Http::fake([
        '*plex.local:32400/library/metadata/501*' => Http::response(plexFixture('metadata_movie')),
    ]);

    $guids = app(PlexClient::class)->metadataGuids('501');

    expect($guids)->toBe(['tmdb' => '603', 'imdb' => 'tt0133093', 'tvdb' => '169']);
});

test('parseGuids ignores unsupported schemes and malformed entries', function () {
    $guids = PlexClient::parseGuids([
        ['id' => 'tmdb://603'],
        ['id' => 'agent://something'],
        ['id' => ''],
        'tvdb://169',
    ]);

    expect($guids)->toBe(['tmdb' => '603', 'tvdb' => '169']);
});

test('guidsFromMetadata parses the modern Guid array as the item’s own ids', function () {
    $parsed = PlexClient::guidsFromMetadata([
        'type' => 'episode',
        'Guid' => [
            ['id' => 'tmdb://63056'],
            ['id' => 'tvdb://3254641'],
        ],
    ]);

    expect($parsed['guids'])->toBe(['tmdb' => '63056', 'tvdb' => '3254641'])
        ->and($parsed['show_guids'])->toBe([])
        ->and($parsed['season_number'])->toBeNull()
        ->and($parsed['episode_number'])->toBeNull();
});

test('guidsFromMetadata parses the legacy tvdb agent guid on an episode as the show’s id plus season/episode', function () {
    $parsed = PlexClient::guidsFromMetadata([
        'type' => 'episode',
        'guid' => 'com.plexapp.agents.thetvdb://81189/1/1?lang=en',
    ]);

    expect($parsed['guids'])->toBe([])
        ->and($parsed['show_guids'])->toBe(['tvdb' => '81189'])
        ->and($parsed['season_number'])->toBe(1)
        ->and($parsed['episode_number'])->toBe(1);
});

test('guidsFromMetadata parses the legacy themoviedb agent guid on a movie as its own tmdb id', function () {
    $parsed = PlexClient::guidsFromMetadata([
        'type' => 'movie',
        'guid' => 'com.plexapp.agents.themoviedb://603?lang=en',
    ]);

    expect($parsed['guids'])->toBe(['tmdb' => '603'])
        ->and($parsed['show_guids'])->toBe([]);
});

test('guidsFromMetadata returns nothing for an unrecognized guid format', function () {
    $parsed = PlexClient::guidsFromMetadata([
        'type' => 'episode',
        'guid' => 'plex://episode/5d9c085f9f6b5c001f6a3a1a',
    ]);

    expect($parsed['guids'])->toBe([])
        ->and($parsed['show_guids'])->toBe([]);
});

test('parseLegacyGuid parses agent, id, and optional season/episode', function () {
    expect(PlexClient::parseLegacyGuid('com.plexapp.agents.thetvdb://121361/6/1?lang=en'))->toBe([
        'scheme' => 'tvdb',
        'value' => '121361',
        'season_number' => 6,
        'episode_number' => 1,
    ])->and(PlexClient::parseLegacyGuid('com.plexapp.agents.imdb://tt0133093?lang=en'))->toBe([
        'scheme' => 'imdb',
        'value' => 'tt0133093',
        'season_number' => null,
        'episode_number' => null,
    ])->and(PlexClient::parseLegacyGuid('plex://movie/5d776b59880197001ec967c6'))->toBeNull();
});

test('it throws a PlexException when retries are exhausted', function () {
    Http::fake([
        '*plex.local:32400/library/metadata/501*' => Http::response(null, 500),
    ]);

    app(PlexClient::class)->metadataGuids('501');
})->throws(PlexException::class);

test('machineIdentifier reads the server id from identity', function () {
    Http::fake([
        '*plex.local:32400/identity*' => Http::response(plexFixture('identity')),
    ]);

    expect(app(PlexClient::class)->machineIdentifier())->toBe('abc123def456');
});

test('librarySections returns only movie and show sections', function () {
    Http::fake([
        '*plex.local:32400/library/sections*' => Http::response(plexFixture('library_sections')),
    ]);

    expect(app(PlexClient::class)->librarySections())->toBe([
        ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ['key' => '2', 'type' => 'show', 'title' => 'TV Shows'],
        ['key' => '4', 'type' => 'movie', 'title' => 'Kids Movies'],
        ['key' => '5', 'type' => 'show', 'title' => 'Kids Shows'],
    ]);
});

test('sectionItems pages a section with includeGuids and parses external ids', function () {
    Http::fake([
        '*plex.local:32400/library/sections/1/all*' => Http::response(plexFixture('library_section_movies')),
    ]);

    $page = app(PlexClient::class)->sectionItems('1', 0, 200);

    expect($page['total_size'])->toBe(2)
        ->and($page['items'])->toHaveCount(2)
        ->and(PlexClient::parseGuids($page['items'][0]['Guid']))->toBe(['imdb' => 'tt13157592', 'tmdb' => '937249'])
        ->and($page['items'][1]['Guid'] ?? [])->toBe([]);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Container-Start', '0'));
    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Container-Size', '200'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'includeGuids=1'));
});

test('episodeRatingKey matches by season and episode number', function () {
    Http::fake([
        '*plex.local:32400/library/metadata/800/allLeaves*' => Http::response(plexFixture('all_leaves_show')),
    ]);

    expect(app(PlexClient::class)->episodeRatingKey('800', 2, 1))->toBe('903')
        ->and(app(PlexClient::class)->episodeRatingKey('800', 1, 1))->toBe('901')
        ->and(app(PlexClient::class)->episodeRatingKey('800', 9, 9))->toBeNull();
});
