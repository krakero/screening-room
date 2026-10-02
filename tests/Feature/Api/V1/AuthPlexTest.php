<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('a linked plex account issues a token', function () {
    $user = User::factory()->withLinkedPlex('plex-account-uuid')->create();

    Http::fake([
        'plex.tv/api/v2/user' => Http::response(['id' => 1, 'uuid' => 'plex-account-uuid', 'username' => 'vmitchell85', 'email' => 'v@example.com']),
    ]);

    $response = $this->postJson('/api/v1/auth/plex', [
        'plex_token' => 'completed-plex-token',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'timezone', 'accent', 'created_at']])
        ->assertJsonPath('user.id', $user->id);

    expect(PersonalAccessToken::query()->where('name', 'iPhone 15 Pro')->exists())->toBeTrue();
});

test('an unlinked plex account is rejected and no user is created', function () {
    Http::fake([
        'plex.tv/api/v2/user' => Http::response(['id' => 1, 'uuid' => 'a-stranger-uuid', 'username' => 'stranger', 'email' => 's@example.com']),
    ]);

    $countBefore = User::query()->count();

    $response = $this->postJson('/api/v1/auth/plex', [
        'plex_token' => 'completed-plex-token',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('plex_token');

    expect(User::query()->count())->toBe($countBefore);
});

test('an invalid plex token is rejected', function () {
    Http::fake([
        'plex.tv/api/v2/user' => Http::response(null, 401),
    ]);

    $response = $this->postJson('/api/v1/auth/plex', [
        'plex_token' => 'bad-token',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('plex_token');
});

test('plex_token and device_name are required', function () {
    $response = $this->postJson('/api/v1/auth/plex', []);

    $response->assertUnprocessable()->assertJsonValidationErrors(['plex_token', 'device_name']);
});

test('plex auth is throttled after five attempts', function () {
    Http::fake([
        'plex.tv/api/v2/user' => Http::response(null, 401),
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/plex', [
            'plex_token' => 'bad-token',
            'device_name' => 'iPhone 15 Pro',
        ]);
    }

    $response = $this->postJson('/api/v1/auth/plex', [
        'plex_token' => 'bad-token',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertStatus(429);
});
