<?php

use App\Services\Pushover\PushoverClient;
use App\Services\Pushover\PushoverException;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('test connection is false when pushover is not configured', function () {
    expect(app(PushoverClient::class)->testConnection())->toBeFalse();

    Http::assertNothingSent();
});

test('test connection validates the configured keys against pushover', function () {
    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'user-key',
        'pushover.app_token' => 'app-token',
    ]);

    Http::fake([
        'https://api.pushover.net/1/users/validate.json' => Http::response(['status' => 1]),
    ]);

    expect(app(PushoverClient::class)->testConnection())->toBeTrue();

    Http::assertSent(fn ($request) => $request['token'] === 'app-token' && $request['user'] === 'user-key');
});

test('test connection is false when pushover reports an invalid key', function () {
    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'bad-key',
        'pushover.app_token' => 'app-token',
    ]);

    Http::fake([
        'https://api.pushover.net/1/users/validate.json' => Http::response(['status' => 0, 'errors' => ['user identifier is invalid']], 400),
    ]);

    expect(app(PushoverClient::class)->testConnection())->toBeFalse();
});

test('send posts the message to pushover', function () {
    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'user-key',
        'pushover.app_token' => 'app-token',
    ]);

    Http::fake([
        'https://api.pushover.net/1/messages.json' => Http::response(['status' => 1]),
    ]);

    app(PushoverClient::class)->send('Now available', 'Fight Club', 'https://screening-room.test/titles/1');

    Http::assertSent(fn ($request) => $request['title'] === 'Now available'
        && $request['message'] === 'Fight Club'
        && $request['url'] === 'https://screening-room.test/titles/1'
        && $request['token'] === 'app-token'
        && $request['user'] === 'user-key');
});

test('send throws when pushover responds with an error', function () {
    app(IntegrationSettings::class)->setMany([
        'pushover.user_key' => 'user-key',
        'pushover.app_token' => 'app-token',
    ]);

    Http::fake([
        'https://api.pushover.net/1/messages.json' => Http::response(['status' => 0], 400),
    ]);

    app(PushoverClient::class)->send('Title', 'Message');
})->throws(PushoverException::class);
