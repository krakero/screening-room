<?php

use App\Models\User;
use App\Services\Qbittorrent\QbittorrentClient;
use App\Services\Qbittorrent\QbittorrentException;
use App\Services\Qbittorrent\Torrent;

function bindQbittorrentClient(bool $configured, ?Closure $torrents = null): void
{
    $client = Mockery::mock(QbittorrentClient::class);
    $client->shouldReceive('configured')->andReturn($configured);
    $client->shouldReceive('webUiUrl')->andReturn($configured ? 'http://qbit.test:8080' : null);

    if ($torrents !== null) {
        $client->shouldReceive('torrents')->andReturnUsing($torrents);
    }

    app()->instance(QbittorrentClient::class, $client);
}

test('downloads requires authentication', function () {
    $this->getJson('/api/v1/downloads')->assertUnauthorized();
});

test('downloads returns an empty list when qbittorrent is not configured', function () {
    bindQbittorrentClient(false);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/v1/downloads')
        ->assertOk()
        ->assertExactJson([
            'data' => [],
            'meta' => ['configured' => false, 'web_ui_url' => null],
        ]);
});

test('downloads returns torrents in the contract shape', function () {
    bindQbittorrentClient(true, fn () => collect([
        Torrent::fromApi([
            'hash' => 'abc123',
            'name' => 'Some.Show.S01E01',
            'state' => 'downloading',
            'progress' => 0.5,
            'size' => 1000,
            'downloaded' => 500,
            'dlspeed' => 200,
            'upspeed' => 10,
            'eta' => 60,
            'category' => 'tv',
            'added_on' => 1700000000,
            'num_seeds' => 4,
            'num_leechs' => 2,
            'ratio' => 0.25,
        ]),
    ]));

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/v1/downloads')
        ->assertOk()
        ->assertExactJson([
            'data' => [[
                'hash' => 'abc123',
                'name' => 'Some.Show.S01E01',
                'state' => 'downloading',
                'state_label' => 'Downloading',
                'progress' => 0.5,
                'percent' => 50,
                'size' => 1000,
                'downloaded' => 500,
                'dlspeed' => 200,
                'upspeed' => 10,
                'eta' => 60,
                'category' => 'tv',
                'added_on' => '2023-11-14T22:13:20Z',
                'num_seeds' => 4,
                'num_leechs' => 2,
                'ratio' => 0.25,
            ]],
            'meta' => ['configured' => true, 'web_ui_url' => 'http://qbit.test:8080'],
        ]);
});

test('downloads returns 503 when qbittorrent cannot be reached', function () {
    bindQbittorrentClient(true, fn () => throw new QbittorrentException('Could not reach qBittorrent.'));

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/v1/downloads')
        ->assertStatus(503)
        ->assertJson(['message' => 'Could not reach qBittorrent.']);
});
