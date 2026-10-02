<?php

use App\Models\User;

test('the health endpoint reports ok when installed', function () {
    $response = $this->getJson('/api/v1');

    $response->assertOk()->assertJson(['status' => 'ok', 'version' => 'v1']);
});

test('the health endpoint reports not_configured when not installed, without a 503', function () {
    $this->markNotInstalled();

    $response = $this->getJson('/api/v1');

    $response->assertOk()->assertJson(['status' => 'not_configured', 'version' => 'v1']);
});

test('other api routes return 503 when the app is not set up', function () {
    $this->markNotInstalled();

    $response = $this->getJson('/api/v1/me');

    $response->assertStatus(503)->assertJson(['message' => 'Application is not set up yet.']);
});

test('the token endpoint also returns 503 when the app is not set up', function () {
    $this->markNotInstalled();
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertStatus(503);
});
