<?php

use App\Enums\UpdateChannel;
use App\Models\User;
use App\Services\Updates\AvailableUpdate;
use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdaterClient;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\get;

beforeEach(function () {
    Storage::fake('updater');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('page renders with current version', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable, 'abc123');
    mockUpdaterAvailable(true);
    mockNoUpdateAvailable();

    Livewire::test('pages::settings.updates')
        ->assertSee('1.0.0')
        ->assertSee('Stable')
        ->assertSee('abc');
});

test('page shows manual update instructions when updater is not available', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable);
    mockUpdaterAvailable(false);
    mockNoUpdateAvailable();

    Livewire::test('pages::settings.updates')
        ->assertSee('Manual updates')
        ->assertSee('docker compose pull');
});

test('page shows available update callout', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable);
    mockUpdaterAvailable(true);
    mockUpdateAvailable('1.1.0', 'New features', 'https://example.com/release', UpdateChannel::Stable);

    Livewire::test('pages::settings.updates')
        ->assertSee('Update available')
        ->assertSee('1.1.0')
        ->assertSee('New features')
        ->assertSee('https://example.com/release');
});

test('page shows pre-update dumps', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable);
    mockUpdaterAvailable(true);
    mockNoUpdateAvailable();

    $dumps = [
        [
            'filename' => 'pre-update-1.1.0-2024-01-15_123000.sql.gz',
            'size' => 1024 * 1024,
            'created_at' => now()->subDays(2),
        ],
        [
            'filename' => 'pre-update-1.0.0-2024-01-10_090000.sql.gz',
            'size' => 512 * 1024,
            'created_at' => now()->subDays(7),
        ],
    ];

    mockPreUpdateDumps($dumps);

    Livewire::test('pages::settings.updates')
        ->assertSee('Pre-update dumps')
        ->assertSee('pre-update-1.1.0-2024-01-15_123000.sql.gz')
        ->assertSee('pre-update-1.0.0-2024-01-10_090000.sql.gz');
});

test('check now button triggers update check', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable);
    mockUpdaterAvailable(true);

    $checkCalled = false;
    $checker = Mockery::mock(UpdateChecker::class);
    $checker->shouldReceive('check')
        ->withArgs(function ($force = false) use (&$checkCalled) {
            if ($force === true) {
                $checkCalled = true;
            }

            return true;
        })
        ->andReturn(null);

    app()->instance(UpdateChecker::class, $checker);

    Livewire::test('pages::settings.updates')
        ->call('checkForUpdates');

    expect($checkCalled)->toBeTrue();
});

test('update now button requests update', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable);
    mockUpdateAvailable('1.1.0', 'New version', 'https://example.com/release', UpdateChannel::Stable);

    $client = Mockery::mock(UpdaterClient::class);
    $client->shouldReceive('isAvailable')->andReturn(true);
    $client->shouldReceive('preUpdateDumps')->andReturn([]);
    $client->shouldReceive('status')->andReturn(null);
    $client->shouldReceive('requestUpdate')
        ->with(UpdateChannel::Stable, 'update')
        ->once()
        ->andReturn('request-id-123');

    app()->instance(UpdaterClient::class, $client);

    Livewire::test('pages::settings.updates')
        ->call('updateNow')
        ->assertSet('updatingRequestId', 'request-id-123');
});

test('switching to stable channel shows confirmation modal', function () {
    mockAppVersion('1.0.0', UpdateChannel::Develop);
    mockUpdaterAvailable(true);
    mockNoUpdateAvailable();

    Livewire::test('pages::settings.updates')
        ->call('confirmChannelSwitch', 'stable')
        ->assertSet('showChannelModal', true)
        ->assertSet('pendingChannel', 'stable');
});

test('switching to develop channel shows warning modal', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable);
    mockUpdaterAvailable(true);
    mockNoUpdateAvailable();

    Livewire::test('pages::settings.updates')
        ->call('confirmChannelSwitch', 'develop')
        ->assertSet('showDevelopWarningModal', true)
        ->assertSet('pendingChannel', 'develop');
});

test('channel switch requests update with switch action', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable);
    mockNoUpdateAvailable();

    $client = Mockery::mock(UpdaterClient::class);
    $client->shouldReceive('isAvailable')->andReturn(true);
    $client->shouldReceive('preUpdateDumps')->andReturn([]);
    $client->shouldReceive('status')->andReturn(null);
    $client->shouldReceive('requestUpdate')
        ->with(UpdateChannel::Develop, 'switch')
        ->once()
        ->andReturn('request-id-456');

    app()->instance(UpdaterClient::class, $client);

    Livewire::test('pages::settings.updates')
        ->call('confirmChannelSwitch', 'develop')
        ->call('switchChannel')
        ->assertSet('updatingRequestId', 'request-id-456')
        ->assertSet('pendingChannel', null);
});

test('updating state shows progress', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable);
    mockUpdateAvailable('1.1.0', 'New version', 'https://example.com', UpdateChannel::Stable);

    $client = Mockery::mock(UpdaterClient::class);
    $client->shouldReceive('isAvailable')->andReturn(true);
    $client->shouldReceive('preUpdateDumps')->andReturn([]);
    $client->shouldReceive('requestUpdate')
        ->with(UpdateChannel::Stable, 'update')
        ->once()
        ->andReturn('request-id-123');
    $client->shouldReceive('status')->andReturn([
        'id' => 'request-id-123',
        'state' => 'pulling',
        'message' => 'Downloading image...',
        'from_tag' => 'latest',
        'to_tag' => 'latest',
        'started_at' => now()->toISOString(),
        'finished_at' => null,
        'dump' => null,
    ]);

    app()->instance(UpdaterClient::class, $client);

    Livewire::test('pages::settings.updates')
        ->call('updateNow')
        ->assertSee('Downloading update')
        ->assertSee('Downloading image');
});

test('rolled back state shows error and dump', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable);
    mockUpdateAvailable('1.1.0', 'New version', 'https://example.com', UpdateChannel::Stable);

    $client = Mockery::mock(UpdaterClient::class);
    $client->shouldReceive('isAvailable')->andReturn(true);
    $client->shouldReceive('preUpdateDumps')->andReturn([]);
    $client->shouldReceive('requestUpdate')
        ->with(UpdateChannel::Stable, 'update')
        ->once()
        ->andReturn('request-id-123');
    $client->shouldReceive('status')->andReturn([
        'id' => 'request-id-123',
        'state' => 'rolled_back',
        'message' => 'Migration failed',
        'from_tag' => 'latest',
        'to_tag' => 'latest',
        'started_at' => now()->subMinutes(5)->toISOString(),
        'finished_at' => now()->toISOString(),
        'dump' => 'pre-update-1.1.0-2024-01-15_123000.sql.gz',
    ]);

    app()->instance(UpdaterClient::class, $client);

    Livewire::test('pages::settings.updates')
        ->call('updateNow')
        ->assertSee('Update failed, rolled back')
        ->assertSee('Migration failed')
        ->assertSee('pre-update-1.1.0-2024-01-15_123000.sql.gz');
});

test('status endpoint returns JSON with boot and status', function () {
    mockAppVersion('1.2.3', UpdateChannel::Stable, 'xyz789');

    $client = Mockery::mock(UpdaterClient::class);
    $client->shouldReceive('isAvailable')->andReturn(true);
    $client->shouldReceive('status')->andReturn([
        'id' => 'req-123',
        'state' => 'done',
    ]);

    app()->instance(UpdaterClient::class, $client);

    // Create a boot.json file
    Storage::disk('updater')->put('boot.json', json_encode([
        'state' => 'ready',
        'version' => '1.2.3',
        'message' => null,
        'dump' => null,
        'at' => now()->toISOString(),
    ]));

    $response = get(route('settings.updates.status'));

    $response->assertOk()
        ->assertJson([
            'app_up' => true,
            'heartbeat_ok' => true,
        ])
        ->assertJsonPath('version.version', '1.2.3')
        ->assertJsonPath('version.channel', 'stable')
        ->assertJsonPath('version.commit', 'xyz789')
        ->assertJsonPath('boot.state', 'ready')
        ->assertJsonPath('status.id', 'req-123');
});

test('status endpoint works when boot.json does not exist', function () {
    mockAppVersion('1.0.0', UpdateChannel::Stable);

    $client = Mockery::mock(UpdaterClient::class);
    $client->shouldReceive('isAvailable')->andReturn(false);
    $client->shouldReceive('status')->andReturn(null);

    app()->instance(UpdaterClient::class, $client);

    $response = get(route('settings.updates.status'));

    $response->assertOk()
        ->assertJson([
            'app_up' => true,
            'boot' => null,
            'status' => null,
            'heartbeat_ok' => false,
        ]);
});

// Helper functions to mock services

function mockAppVersion(string $version, UpdateChannel $channel, ?string $commit = null): void
{
    config([
        'app.version' => $version,
        'app.channel' => $channel->value,
        'app.commit' => $commit,
    ]);
}

function mockUpdaterAvailable(bool $available): void
{
    $client = Mockery::mock(UpdaterClient::class);
    $client->shouldReceive('isAvailable')->andReturn($available);
    $client->shouldReceive('preUpdateDumps')->andReturn([]);
    $client->shouldReceive('status')->andReturn(null);

    app()->instance(UpdaterClient::class, $client);
}

function mockNoUpdateAvailable(): void
{
    $checker = Mockery::mock(UpdateChecker::class);
    $checker->shouldReceive('check')->andReturn(null);

    app()->instance(UpdateChecker::class, $checker);
}

function mockUpdateAvailable(string $version, string $summary, ?string $url, UpdateChannel $channel): void
{
    $update = new AvailableUpdate(
        version: $version,
        summary: $summary,
        url: $url,
        published_at: now(),
        channel: $channel
    );

    $checker = Mockery::mock(UpdateChecker::class);
    $checker->shouldReceive('check')->andReturn($update);

    app()->instance(UpdateChecker::class, $checker);
}

function mockPreUpdateDumps(array $dumps): void
{
    $mock = Mockery::mock(UpdaterClient::class);
    $mock->shouldReceive('isAvailable')->andReturn(true);
    $mock->shouldReceive('status')->andReturn(null);
    $mock->shouldReceive('preUpdateDumps')->andReturn($dumps);

    app()->instance(UpdaterClient::class, $mock);
}
