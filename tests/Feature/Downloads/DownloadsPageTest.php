<?php

use App\Models\User;
use App\Services\Qbittorrent\QbittorrentClient;
use App\Services\Qbittorrent\QbittorrentException;
use App\Services\Qbittorrent\Torrent;
use App\Support\IntegrationSettings;
use Livewire\Livewire;

function fakeQbittorrent(array $torrents = [], ?Throwable $failure = null): void
{
    app(IntegrationSettings::class)->set('qbittorrent.url', 'http://qbit.test:8080');

    $client = Mockery::mock(QbittorrentClient::class);
    $client->shouldReceive('configured')->andReturn(true);
    $client->shouldReceive('webUiUrl')->andReturn('http://qbit.test:8080');

    if ($failure) {
        $client->shouldReceive('torrents')->andThrow($failure);
    } else {
        $client->shouldReceive('torrents')->andReturn(collect($torrents));
    }

    app()->instance(QbittorrentClient::class, $client);
}

function torrentRow(array $overrides = []): Torrent
{
    return Torrent::fromApi(array_merge([
        'hash' => 'abc123',
        'name' => 'Some.Show.S01E01.1080p',
        'state' => 'downloading',
        'progress' => 0.42,
        'size' => 2 * 1024 * 1024 * 1024,
        'downloaded' => 900_000_000,
        'dlspeed' => 2_500_000,
        'upspeed' => 0,
        'eta' => 720,
        'category' => 'tv',
        'added_on' => 1_700_000_000,
    ], $overrides));
}

test('guests are redirected to the login page', function () {
    $this->get(route('downloads'))->assertRedirect(route('login'));
});

test('an unconfigured page links to the qBittorrent settings', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('downloads'))
        ->assertOk()
        ->assertSee(route('settings.integrations.qbittorrent'), false)
        ->assertDontSee('Open qBittorrent');
});

test('it renders torrents from the client', function () {
    fakeQbittorrent([
        torrentRow(),
        torrentRow(['hash' => 'def456', 'name' => 'Done.Movie', 'state' => 'uploading', 'progress' => 1, 'dlspeed' => 0, 'eta' => 8640000]),
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::downloads')
        ->assertSee('Some.Show.S01E01.1080p')
        ->assertSee('Downloading')
        ->assertSee('42%')
        ->assertSee('2.4 MB/s')
        ->assertSee('12m')
        ->assertSee('Seeding')
        ->assertSee('Open qBittorrent')
        ->assertSee('1 downloading')
        ->assertSee('1 seeding');
});

test('it shows an empty state when there are no torrents', function () {
    fakeQbittorrent([]);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::downloads')->assertSee('Nothing downloading');
});

test('a client failure shows a callout with the message', function () {
    fakeQbittorrent(failure: new QbittorrentException('Connection refused'));
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::downloads')
        ->assertSee('Connection refused')
        ->assertSeeHtml('data-test="downloads-error"');
});

test('the nav link only shows when qBittorrent is configured', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertDontSee(route('downloads'), false);

    app(IntegrationSettings::class)->set('qbittorrent.url', 'http://qbit.test:8080');

    $this->get(route('dashboard'))->assertSee(route('downloads'), false);
});
