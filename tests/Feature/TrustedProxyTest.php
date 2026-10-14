<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_test/proxy-context', function (Request $request) {
        return response()->json([
            'host' => $request->getHost(),
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]);
    });
});

it('uses forwarded request details from a trusted proxy without trusting the forwarded host', function () {
    $response = $this
        ->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
        ])
        ->withHeaders([
            'X-Forwarded-For' => '100.100.100.10',
            'X-Forwarded-Host' => 'spoofed.example.com',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get('/_test/proxy-context');

    $response->assertOk()->assertExactJson([
        'host' => 'localhost',
        'ip' => '100.100.100.10',
        'secure' => true,
    ]);
});

it('supports a configured proxy address', function () {
    config()->set('trustedproxy.proxies', '192.0.2.10, 198.51.100.0/24');

    $response = $this
        ->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.20',
        ])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.30',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get('/_test/proxy-context');

    $response->assertOk()->assertExactJson([
        'host' => 'localhost',
        'ip' => '203.0.113.30',
        'secure' => true,
    ]);
});

it('ignores forged forwarded headers from an untrusted client', function () {
    $response = $this
        ->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.10',
        ])
        ->withHeaders([
            'X-Forwarded-For' => '100.100.100.10',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-Proto' => 'https',
        ])
        ->get('/_test/proxy-context');

    $response->assertOk()->assertExactJson([
        'host' => 'localhost',
        'ip' => '203.0.113.10',
        'secure' => false,
    ]);
});
