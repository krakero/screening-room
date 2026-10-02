<?php

use App\Models\User;
use App\Services\Stats\StatsCacheVersion;

test('me requires authentication', function () {
    $response = $this->getJson('/api/v1/me');

    $response->assertUnauthorized();
});

test('me returns the authenticated user in the contract shape', function () {
    $user = User::factory()->create([
        'name' => 'Vince',
        'timezone' => 'America/Chicago',
        'accent' => 'amber',
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me');

    $response->assertOk()->assertJson([
        'id' => $user->id,
        'name' => 'Vince',
        'email' => $user->email,
        'timezone' => 'America/Chicago',
        'accent' => 'amber',
    ])->assertJsonMissingPath('password');
});

test('me can update name, timezone and accent', function () {
    $user = User::factory()->create(['accent' => 'amber']);

    $response = $this->actingAs($user, 'sanctum')->putJson('/api/v1/me', [
        'name' => 'New Name',
        'timezone' => 'Europe/London',
        'accent' => '#123abc',
    ]);

    $response->assertOk()->assertJson([
        'name' => 'New Name',
        'timezone' => 'Europe/London',
        'accent' => '#123abc',
    ]);

    expect($user->fresh())
        ->name->toBe('New Name')
        ->timezone->toBe('Europe/London')
        ->accent->toBe('#123abc');
});

test('me rejects an invalid accent', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->putJson('/api/v1/me', [
        'accent' => 'not-a-real-preset-or-hex',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('accent');
});

test('me rejects an invalid timezone', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->putJson('/api/v1/me', [
        'timezone' => 'Not/A/Timezone',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('timezone');
});

test('updating the timezone bumps the stats cache version', function () {
    $user = User::factory()->create(['timezone' => 'America/Chicago']);
    $before = app(StatsCacheVersion::class)->current();

    $this->actingAs($user, 'sanctum')->putJson('/api/v1/me', [
        'timezone' => 'Europe/London',
    ])->assertOk();

    expect(app(StatsCacheVersion::class)->current())->toBe($before + 1);
});

test('updating only the name does not bump the stats cache version', function () {
    $user = User::factory()->create();
    $before = app(StatsCacheVersion::class)->current();

    $this->actingAs($user, 'sanctum')->putJson('/api/v1/me', [
        'name' => 'New Name',
    ])->assertOk();

    expect(app(StatsCacheVersion::class)->current())->toBe($before);
});
