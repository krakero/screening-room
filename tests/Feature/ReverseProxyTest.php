<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Route::get('/__reverse-proxy-test', fn () => response()->json([
        'asset' => asset('build/app.css'),
        'url' => url('/login'),
        'secure' => request()->isSecure(),
    ]));
});

afterEach(function () {
    URL::forceScheme(null);
});

test('https forwarded by a proxy on a private address produces https urls', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.17.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'screening-room.example.ts.net'])
        ->getJson('/__reverse-proxy-test')
        ->assertOk()
        ->assertJson([
            'asset' => 'https://screening-room.example.ts.net/build/app.css',
            'url' => 'https://screening-room.example.ts.net/login',
            'secure' => true,
        ]);
});

test('forwarded headers from an untrusted public address are ignored', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example.com'])
        ->getJson('/__reverse-proxy-test')
        ->assertOk()
        ->assertJson([
            'asset' => 'http://localhost/build/app.css',
            'secure' => false,
        ]);
});

test('TRUSTED_PROXIES can be widened to trust any caller', function () {
    config(['trustedproxy.proxies' => '*']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->getJson('/__reverse-proxy-test')
        ->assertOk()
        ->assertJson(['secure' => true]);
});

test('APP_FORCE_HTTPS forces https urls even without forwarded headers', function () {
    config(['app.force_https' => true]);
    app()->getProvider(AppServiceProvider::class)->boot();

    $this->getJson('/__reverse-proxy-test')
        ->assertOk()
        ->assertJson([
            'asset' => 'https://localhost/build/app.css',
            'url' => 'https://localhost/login',
        ]);
});
