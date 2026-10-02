<?php

use App\Models\User;
use App\Support\IntegrationSettings;

test('settings integrations requires authentication', function () {
    $response = $this->getJson('/api/v1/settings/integrations');

    $response->assertUnauthorized();
});

test('settings integrations reports configured status without secrets', function () {
    $user = User::factory()->create();

    app(IntegrationSettings::class)->setMany([
        'plex.token' => 'secret-plex-token',
        'plex.url' => 'https://plex.test',
        'qbittorrent.url' => 'http://qbit.test:8080',
        'qbittorrent.password' => 'secret-qbit-password',
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/settings/integrations');

    $response->assertOk()->assertJson([
        'plex' => ['configured' => true],
        'seerr' => ['configured' => false],
        'sonarr' => ['configured' => false],
        'radarr' => ['configured' => false],
        'qbittorrent' => ['configured' => true],
        'mdblist' => ['configured' => false],
        'pushover' => ['configured' => false],
        'trakt' => ['configured' => false],
    ]);

    $response->assertDontSee('secret-plex-token');
    $response->assertDontSee('secret-qbit-password');
});
