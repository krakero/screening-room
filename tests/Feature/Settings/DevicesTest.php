<?php

use App\Models\User;
use Livewire\Livewire;

test('devices page requires authentication', function () {
    $response = $this->get(route('devices.edit'));

    $response->assertRedirect(route('login'));
});

test('devices page lists the user\'s sanctum tokens', function () {
    $user = User::factory()->create();
    $user->createToken('iPhone 15 Pro');
    $user->createToken('Pixel 9');

    $response = $this->actingAs($user)->get(route('devices.edit'));

    $response->assertOk();
    $response->assertSee('iPhone 15 Pro');
    $response->assertSee('Pixel 9');
});

test('devices page shows an empty state with no tokens', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('devices.edit'));

    $response->assertOk();
    $response->assertSee('No devices signed in');
});

test('devices page never leaks the plain-text token value', function () {
    $user = User::factory()->create();
    $token = $user->createToken('iPhone 15 Pro')->plainTextToken;

    $response = $this->actingAs($user)->get(route('devices.edit'));

    $response->assertOk()->assertDontSee($token);
});

test('a device can be revoked', function () {
    $user = User::factory()->create();
    $token = $user->createToken('iPhone 15 Pro');

    Livewire::actingAs($user)
        ->test('pages::settings.devices')
        ->call('confirmRevoke', $token->accessToken->id)
        ->call('revoke');

    expect($user->tokens()->count())->toBe(0);
});

test('revoking a device only removes that user\'s token', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $token = $user->createToken('iPhone 15 Pro');
    $otherToken = $other->createToken('Other Phone');

    Livewire::actingAs($user)
        ->test('pages::settings.devices')
        ->call('confirmRevoke', $token->accessToken->id)
        ->call('revoke');

    expect($user->tokens()->count())->toBe(0);
    expect($other->tokens()->count())->toBe(1);
    expect($other->fresh()->tokens()->first()->id)->toBe($otherToken->accessToken->id);
});
