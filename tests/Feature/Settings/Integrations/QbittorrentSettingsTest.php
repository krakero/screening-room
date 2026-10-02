<?php

use App\Models\User;
use App\Services\Qbittorrent\QbittorrentClient;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('settings.integrations.qbittorrent'))->assertRedirect(route('login'));
});

test('the qbittorrent page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('settings.integrations.qbittorrent'))
        ->assertOk()
        ->assertSee('qBittorrent')
        ->assertDontSee('@if');
});

test('the server url is required and must be a url', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.integrations.qbittorrent')
        ->set('qbittorrentUrl', '')
        ->call('saveQbittorrent')
        ->assertHasErrors(['qbittorrentUrl' => 'required'])
        ->set('qbittorrentUrl', 'not a url')
        ->call('saveQbittorrent')
        ->assertHasErrors(['qbittorrentUrl' => 'url']);
});

test('qbittorrent settings can be saved and the cached session is forgotten', function () {
    $this->actingAs(User::factory()->create());
    Cache::put('qbittorrent:sid', 'old-sid');

    Livewire::test('pages::settings.integrations.qbittorrent')
        ->set('qbittorrentUrl', 'http://qbit.local:8080/')
        ->set('qbittorrentUsername', 'admin')
        ->set('qbittorrentPassword', 'secret')
        ->call('saveQbittorrent')
        ->assertHasNoErrors();

    $settings = app(IntegrationSettings::class);

    expect($settings->get('qbittorrent.url'))->toBe('http://qbit.local:8080')
        ->and($settings->get('qbittorrent.username'))->toBe('admin')
        ->and($settings->get('qbittorrent.password'))->toBe('secret')
        ->and(Cache::has('qbittorrent:sid'))->toBeFalse();
});

test('leaving the password blank keeps the existing value', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->set('qbittorrent.password', 'original');

    Livewire::test('pages::settings.integrations.qbittorrent')
        ->set('qbittorrentUrl', 'http://qbit.local:8080')
        ->set('qbittorrentPassword', '')
        ->call('saveQbittorrent')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->get('qbittorrent.password'))->toBe('original');
});

test('test connection reports success with the version', function () {
    $this->actingAs(User::factory()->create());

    $client = Mockery::mock(QbittorrentClient::class);
    $client->shouldReceive('testConnection')->once()->andReturn(true);
    $client->shouldReceive('version')->once()->andReturn('v5.0.1');
    app()->instance(QbittorrentClient::class, $client);

    Livewire::test('pages::settings.integrations.qbittorrent')
        ->set('qbittorrentUrl', 'http://qbit.local:8080')
        ->call('testQbittorrent')
        ->assertDispatched('toast-show', fn ($event, $params) => $params['slots']['text'] === 'qBittorrent connection successful (v5.0.1).' && $params['dataset']['variant'] === 'success');
});

test('test connection reports failure', function () {
    $this->actingAs(User::factory()->create());

    $client = Mockery::mock(QbittorrentClient::class);
    $client->shouldReceive('testConnection')->once()->andReturn(false);
    app()->instance(QbittorrentClient::class, $client);

    Livewire::test('pages::settings.integrations.qbittorrent')
        ->set('qbittorrentUrl', 'http://qbit.local:8080')
        ->call('testQbittorrent')
        ->assertDispatched('toast-show', fn ($event, $params) => $params['slots']['text'] === 'qBittorrent connection failed.' && $params['dataset']['variant'] === 'danger');
});

test('test connection saves unsaved form values before testing', function () {
    $this->actingAs(User::factory()->create());
    Cache::put('qbittorrent:sid', 'old-sid');

    $client = Mockery::mock(QbittorrentClient::class);
    $client->shouldReceive('testConnection')->once()->andReturn(true);
    $client->shouldReceive('version')->once()->andReturn('v5.0.1');
    app()->instance(QbittorrentClient::class, $client);

    Livewire::test('pages::settings.integrations.qbittorrent')
        ->set('qbittorrentUrl', 'http://qbit.local:8080/')
        ->set('qbittorrentUsername', 'admin')
        ->set('qbittorrentPassword', 'secret')
        ->call('testQbittorrent')
        ->assertSet('qbittorrentPassword', '')
        ->assertDispatched('toast-show', fn ($event, $params) => $params['slots']['text'] === 'qBittorrent connection successful (v5.0.1).');

    $settings = app(IntegrationSettings::class);

    expect($settings->get('qbittorrent.url'))->toBe('http://qbit.local:8080')
        ->and($settings->get('qbittorrent.username'))->toBe('admin')
        ->and($settings->get('qbittorrent.password'))->toBe('secret')
        ->and(Cache::has('qbittorrent:sid'))->toBeFalse();
});

test('test connection with a blank password keeps the saved password', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->set('qbittorrent.password', 'original');

    $client = Mockery::mock(QbittorrentClient::class);
    $client->shouldReceive('testConnection')->once()->andReturn(false);
    app()->instance(QbittorrentClient::class, $client);

    Livewire::test('pages::settings.integrations.qbittorrent')
        ->set('qbittorrentUrl', 'http://qbit.local:8080')
        ->set('qbittorrentPassword', '')
        ->call('testQbittorrent');

    expect(app(IntegrationSettings::class)->get('qbittorrent.password'))->toBe('original');
});

test('test connection with an invalid url saves nothing and runs no test', function () {
    $this->actingAs(User::factory()->create());

    $client = Mockery::mock(QbittorrentClient::class);
    $client->shouldReceive('testConnection')->never();
    app()->instance(QbittorrentClient::class, $client);

    Livewire::test('pages::settings.integrations.qbittorrent')
        ->set('qbittorrentUrl', '')
        ->set('qbittorrentPassword', 'secret')
        ->call('testQbittorrent')
        ->assertHasErrors(['qbittorrentUrl' => 'required'])
        ->assertNotDispatched('toast-show');

    expect(app(IntegrationSettings::class)->get('qbittorrent.password'))->toBeNull();
});
