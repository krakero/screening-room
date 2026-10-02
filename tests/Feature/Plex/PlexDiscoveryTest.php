<?php

use App\Services\Plex\PlexDiscovery;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('clientIdentifier generates a uuid once and reuses it', function () {
    $discovery = app(PlexDiscovery::class);

    $first = $discovery->clientIdentifier();
    $second = $discovery->clientIdentifier();

    expect($first)->not->toBeEmpty()
        ->and($second)->toBe($first)
        ->and(app(IntegrationSettings::class)->get('plex.client_identifier'))->toBe($first);
});

test('createPin posts to plex.tv with the client headers and returns the auth url', function () {
    Http::fake([
        'plex.tv/api/v2/pins' => Http::response(['id' => 123, 'code' => 'ABCD']),
    ]);

    $pin = app(PlexDiscovery::class)->createPin();

    expect($pin['id'])->toBe(123)
        ->and($pin['code'])->toBe('ABCD')
        ->and($pin['auth_url'])->toContain('https://app.plex.tv/auth#?')
        ->and($pin['auth_url'])->toContain('code=ABCD')
        ->and($pin['auth_url'])->toContain('context%5Bdevice%5D%5Bproduct%5D=Screening+Room');

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Product', 'Screening Room')
        && $request->hasHeader('X-Plex-Client-Identifier')
        && $request['strong'] === 'true');
});

test('createPin throws when plex.tv does not return an id and code', function () {
    Http::fake([
        'plex.tv/api/v2/pins' => Http::response([]),
    ]);

    app(PlexDiscovery::class)->createPin();
})->throws(RuntimeException::class);

test('checkPin returns null while the pin is still pending', function () {
    Http::fake([
        'plex.tv/api/v2/pins/123' => Http::response(['id' => 123, 'authToken' => null]),
    ]);

    expect(app(PlexDiscovery::class)->checkPin(123))->toBeNull();
});

test('checkPin returns the auth token once signed in', function () {
    Http::fake([
        'plex.tv/api/v2/pins/123' => Http::response(['id' => 123, 'authToken' => 'user-token']),
    ]);

    expect(app(PlexDiscovery::class)->checkPin(123))->toBe('user-token');
});

test('account returns the id, uuid, username, and email', function () {
    Http::fake([
        'plex.tv/api/v2/user' => Http::response([
            'id' => 555,
            'uuid' => 'account-uuid',
            'username' => 'vmitchell85',
            'email' => 'v@example.com',
        ]),
    ]);

    $account = app(PlexDiscovery::class)->account('user-token');

    expect($account)->toBe([
        'id' => 555,
        'uuid' => 'account-uuid',
        'username' => 'vmitchell85',
        'email' => 'v@example.com',
    ]);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Token', 'user-token'));
});

test('account returns null when the request fails', function () {
    Http::fake([
        'plex.tv/api/v2/user' => Http::response(null, 401),
    ]);

    expect(app(PlexDiscovery::class)->account('bad-token'))->toBeNull();
});

test('servers parses owned and shared servers with multiple connections and skips non-server resources', function () {
    Http::fake([
        'plex.tv/api/v2/resources*' => Http::response([
            [
                'provides' => 'server',
                'clientIdentifier' => 'owned-machine-id',
                'name' => 'Home Server',
                'owned' => true,
                'presence' => true,
                'connections' => [
                    ['uri' => 'https://192-168-1-5.abc.plex.direct:32400', 'local' => true, 'relay' => false, 'protocol' => 'https'],
                    ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
                ],
            ],
            [
                'provides' => 'player',
                'clientIdentifier' => 'not-a-server',
                'name' => 'Some Player',
            ],
            [
                'provides' => 'server',
                'clientIdentifier' => 'shared-machine-id',
                'name' => "Friend's Server",
                'owned' => false,
                'presence' => false,
                'accessToken' => 'shared-access-token',
                'connections' => [
                    ['uri' => 'https://relay.plex.tv/abc', 'local' => false, 'relay' => true, 'protocol' => 'https'],
                ],
            ],
        ]),
    ]);

    $servers = app(PlexDiscovery::class)->servers('user-token');

    expect($servers)->toHaveCount(2)
        ->and($servers[0]['machine_identifier'])->toBe('owned-machine-id')
        ->and($servers[0]['owned'])->toBeTrue()
        ->and($servers[0]['connections'])->toHaveCount(2)
        ->and($servers[1]['machine_identifier'])->toBe('shared-machine-id')
        ->and($servers[1]['owned'])->toBeFalse()
        ->and($servers[1]['presence'])->toBeFalse()
        ->and($servers[1]['access_token'])->toBe('shared-access-token');
});

test('selectConnection prefers local https, then local http, then remote https, then relay', function () {
    Http::fake([
        'local.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'the-machine-id']]),
        '192.168.1.5:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'the-machine-id']]),
        'remote.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'the-machine-id']]),
        'relay.plex.tv/abc/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'the-machine-id']]),
    ]);

    $server = [
        'connections' => [
            ['uri' => 'https://relay.plex.tv/abc', 'local' => false, 'relay' => true, 'protocol' => 'https'],
            ['uri' => 'https://remote.plex.direct:32400', 'local' => false, 'relay' => false, 'protocol' => 'https'],
            ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
            ['uri' => 'https://local.plex.direct:32400', 'local' => true, 'relay' => false, 'protocol' => 'https'],
        ],
    ];

    $winner = app(PlexDiscovery::class)->selectConnection($server, 'the-machine-id', 'user-token');

    expect($winner['url'])->toBe('https://local.plex.direct:32400')
        ->and($winner['mode'])->toBe('local');
});

test('selectConnection falls back to relay when every other candidate fails', function () {
    Http::fake([
        'local.plex.direct:32400/identity' => Http::response(null, 500),
        '192.168.1.5:32400/identity' => Http::response(null, 500),
        'remote.plex.direct:32400/identity' => Http::response(null, 500),
        'relay.plex.tv/abc/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'the-machine-id']]),
    ]);

    $server = [
        'connections' => [
            ['uri' => 'https://local.plex.direct:32400', 'local' => true, 'relay' => false, 'protocol' => 'https'],
            ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
            ['uri' => 'https://remote.plex.direct:32400', 'local' => false, 'relay' => false, 'protocol' => 'https'],
            ['uri' => 'https://relay.plex.tv/abc', 'local' => false, 'relay' => true, 'protocol' => 'https'],
        ],
    ];

    $winner = app(PlexDiscovery::class)->selectConnection($server, 'the-machine-id', 'user-token');

    expect($winner['url'])->toBe('https://relay.plex.tv/abc')
        ->and($winner['mode'])->toBe('relay');
});

test('selectConnection returns null when no candidate reports the expected machine id', function () {
    Http::fake([
        'local.plex.direct:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'a-different-machine']]),
    ]);

    $server = [
        'connections' => [
            ['uri' => 'https://local.plex.direct:32400', 'local' => true, 'relay' => false, 'protocol' => 'https'],
        ],
    ];

    expect(app(PlexDiscovery::class)->selectConnection($server, 'the-machine-id', 'user-token'))->toBeNull();
});

test('reselectConnection updates plex.url on success and records when it last checked', function () {
    Carbon::setTestNow('2026-09-25 12:00:00');

    app(IntegrationSettings::class)->setMany([
        'plex.token' => 'user-token',
        'plex.selected_machine_identifier' => 'the-machine-id',
        'plex.servers' => [[
            'machine_identifier' => 'the-machine-id',
            'name' => 'Home Server',
            'connections' => [
                ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
            ],
        ]],
    ]);

    Http::fake([
        '192.168.1.5:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'the-machine-id']]),
    ]);

    $ok = app(PlexDiscovery::class)->reselectConnection();

    expect($ok)->toBeTrue();

    $settings = app(IntegrationSettings::class);

    expect($settings->get('plex.url'))->toBe('http://192.168.1.5:32400')
        ->and($settings->get('plex.connection_mode'))->toBe('local')
        ->and($settings->get('plex.last_connection_check_at'))->toBe('2026-09-25T12:00:00+00:00');

    Carbon::setTestNow();
});

test('reselectConnection is rate-limited unless forced', function () {
    Carbon::setTestNow('2026-09-25 12:00:00');

    app(IntegrationSettings::class)->setMany([
        'plex.token' => 'user-token',
        'plex.selected_machine_identifier' => 'the-machine-id',
        'plex.last_connection_check_at' => '2026-09-25T11:55:00+00:00',
        'plex.servers' => [[
            'machine_identifier' => 'the-machine-id',
            'name' => 'Home Server',
            'connections' => [
                ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
            ],
        ]],
    ]);

    Http::fake();

    expect(app(PlexDiscovery::class)->reselectConnection())->toBeFalse();

    Http::assertNothingSent();

    Carbon::setTestNow();
});

test('reselectConnection marks plex.unreachable when every candidate connection fails', function () {
    Carbon::setTestNow('2026-09-25 12:00:00');

    app(IntegrationSettings::class)->setMany([
        'plex.token' => 'user-token',
        'plex.selected_machine_identifier' => 'the-machine-id',
        'plex.servers' => [[
            'machine_identifier' => 'the-machine-id',
            'name' => 'Home Server',
            'connections' => [
                ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
            ],
        ]],
    ]);

    Http::fake([
        '192.168.1.5:32400/identity' => Http::response(null, 500),
    ]);

    expect(app(PlexDiscovery::class)->reselectConnection())->toBeFalse();

    $settings = app(IntegrationSettings::class);

    expect($settings->get('plex.unreachable'))->toBeTrue()
        ->and($settings->get('plex.unreachable_since'))->toBe('2026-09-25T12:00:00+00:00');

    Carbon::setTestNow();
});

test('reselectConnection clears plex.unreachable once a connection succeeds again', function () {
    Carbon::setTestNow('2026-09-25 12:00:00');

    app(IntegrationSettings::class)->setMany([
        'plex.token' => 'user-token',
        'plex.selected_machine_identifier' => 'the-machine-id',
        'plex.unreachable' => true,
        'plex.unreachable_since' => '2026-09-24T12:00:00+00:00',
        'plex.servers' => [[
            'machine_identifier' => 'the-machine-id',
            'name' => 'Home Server',
            'connections' => [
                ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
            ],
        ]],
    ]);

    Http::fake([
        '192.168.1.5:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'the-machine-id']]),
    ]);

    expect(app(PlexDiscovery::class)->reselectConnection())->toBeTrue();

    expect(app(IntegrationSettings::class)->get('plex.unreachable'))->toBeFalse();

    Carbon::setTestNow();
});

test('reselectConnection does nothing when manual override is on', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.manual_override' => true,
        'plex.token' => 'user-token',
        'plex.selected_machine_identifier' => 'the-machine-id',
        'plex.servers' => [[
            'machine_identifier' => 'the-machine-id',
            'name' => 'Home Server',
            'connections' => [
                ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
            ],
        ]],
    ]);

    Http::fake();

    expect(app(PlexDiscovery::class)->reselectConnection(force: true))->toBeFalse();

    Http::assertNothingSent();
});
