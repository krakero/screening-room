<?php

use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('starting sign in with plex from setup creates a pin and dispatches the auth url', function () {
    $this->markNotInstalled();
    $this->actingAs(User::factory()->create());

    Http::fake([
        'plex.tv/api/v2/pins' => Http::response(['id' => 99, 'code' => 'WXYZ']),
    ]);

    Livewire::test('pages::setup.plex')
        ->call('startPlexSignIn')
        ->assertSet('plexSignInPolling', true)
        ->assertDispatched('plex-auth-opened');
});

test('polling sign in from setup stores the token and discovered servers', function () {
    $this->markNotInstalled();
    $this->actingAs(User::factory()->create());

    Http::fake([
        'plex.tv/api/v2/pins/99' => Http::response(['id' => 99, 'authToken' => 'user-token']),
        'plex.tv/api/v2/user' => Http::response(['id' => 555, 'uuid' => 'account-uuid', 'username' => 'vmitchell85', 'email' => 'v@example.com']),
        'plex.tv/api/v2/resources*' => Http::response([
            [
                'provides' => 'server',
                'clientIdentifier' => 'the-machine-id',
                'name' => 'Home Server',
                'owned' => true,
                'presence' => true,
                'connections' => [
                    ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
                ],
            ],
        ]),
    ]);

    Livewire::test('pages::setup.plex')
        ->set('plexSignInPinId', 99)
        ->set('plexSignInPolling', true)
        ->call('pollPlexSignIn')
        ->assertSet('plexAccountName', 'vmitchell85')
        ->assertSee('Home Server')
        ->assertDontSee('@if');

    expect(app(IntegrationSettings::class)->get('plex.servers'))->toHaveCount(1);
});

test('selecting a discovered server from setup saves the winning connection', function () {
    $this->markNotInstalled();
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany([
        'plex.token' => 'user-token',
        'plex.servers' => [[
            'machine_identifier' => 'the-machine-id',
            'name' => 'Home Server',
            'owned' => true,
            'presence' => true,
            'access_token' => 'user-token',
            'connections' => [
                ['uri' => 'http://192.168.1.5:32400', 'local' => true, 'relay' => false, 'protocol' => 'http'],
            ],
        ]],
    ]);

    Http::fake([
        '192.168.1.5:32400/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'the-machine-id']]),
    ]);

    Livewire::test('pages::setup.plex')
        ->call('selectPlexServer', 'the-machine-id')
        ->assertSet('plexUrl', 'http://192.168.1.5:32400')
        ->assertDispatched('toast-show', fn ($name, $params): bool => ($params['slots']['text'] ?? null) === 'Connected to Home Server.');

    expect(app(IntegrationSettings::class)->get('plex.url'))->toBe('http://192.168.1.5:32400');
});
