<?php

use App\Models\PlexLibraryItem;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('it does nothing when manual override is on', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'token',
        'plex.manual_override' => true,
    ]);

    $this->artisan('plex:recheck-connection')
        ->assertExitCode(0)
        ->expectsOutputToContain('Manual Plex URL override is on');

    Http::assertNothingSent();
});

test('it picks a working connection and updates plex.url', function () {
    Carbon::setTestNow('2026-09-26 03:10:00');

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://old-address:32400',
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

    $this->artisan('plex:recheck-connection')
        ->assertExitCode(0)
        ->expectsOutputToContain('using http://192.168.1.5:32400');

    expect(app(IntegrationSettings::class)->get('plex.url'))->toBe('http://192.168.1.5:32400');

    Carbon::setTestNow();
});

test('it marks Plex unreachable in the output when every connection fails', function () {
    Carbon::setTestNow('2026-09-26 03:10:00');

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://old-address:32400',
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

    $this->artisan('plex:recheck-connection')
        ->assertExitCode(0)
        ->expectsOutputToContain('Plex is unreachable on every known connection');

    expect(app(IntegrationSettings::class)->get('plex.unreachable'))->toBeTrue();

    Carbon::setTestNow();
});

test('it never calls Plex when discovery has no selected server', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'token',
    ]);

    $this->artisan('plex:recheck-connection')
        ->assertExitCode(0)
        ->expectsOutputToContain('nothing to do');

    Http::assertNothingSent();
});

test('it does not affect plex_library_items', function () {
    PlexLibraryItem::factory()->create([
        'plex_rating_key' => 'unrelated',
        'machine_identifier' => 'the-machine-id',
        'type' => 'movie',
        'tmdb_id' => 111111,
    ]);

    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'token',
        'plex.manual_override' => true,
    ]);

    $this->artisan('plex:recheck-connection')->assertExitCode(0);

    expect(PlexLibraryItem::query()->where('plex_rating_key', 'unrelated')->exists())->toBeTrue();
});
