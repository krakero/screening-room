<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

test('a valid login issues a token', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'timezone', 'accent', 'created_at']])
        ->assertJsonPath('user.id', $user->id);

    expect(PersonalAccessToken::query()->where('name', 'iPhone 15 Pro')->exists())->toBeTrue();
});

test('an invalid password is rejected', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('an unknown email is rejected', function () {
    $response = $this->postJson('/api/v1/auth/token', [
        'email' => 'nobody@example.com',
        'password' => 'password',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('email, password and device_name are required', function () {
    $response = $this->postJson('/api/v1/auth/token', []);

    $response->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'device_name']);
});

test('login is throttled after five attempts', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'iPhone 15 Pro',
        ]);
    }

    $response = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertStatus(429);
});

test('a token can be revoked', function () {
    $user = User::factory()->create();
    $token = $user->createToken('iPhone 15 Pro');

    $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->deleteJson('/api/v1/auth/token');

    $response->assertNoContent();
    expect(PersonalAccessToken::query()->whereKey($token->accessToken->id)->exists())->toBeFalse();
});

test('revoking a token requires authentication', function () {
    $response = $this->deleteJson('/api/v1/auth/token');

    $response->assertUnauthorized();
});
