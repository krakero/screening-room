<?php

use App\Models\User;
use App\Support\IntegrationSettings;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('settings.integrations'));

    $response->assertRedirect(route('login'));
});

test('the integrations index redirects to plex when nothing is configured', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings.integrations'));

    $response->assertRedirect(route('settings.integrations.plex'));
});

test('the integrations index redirects to the first unconfigured integration', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->set('plex.token', 'configured');

    $response = $this->get(route('settings.integrations'));

    $response->assertRedirect(route('settings.integrations.seerr'));
});
