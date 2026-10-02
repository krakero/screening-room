<?php

use App\Services\Plex\PlexException;

test('connectionFailed appends the plex.direct hint for a TLS certificate failure', function () {
    $exception = PlexException::connectionFailed('/identity', 'cURL error 60: SSL certificate problem: self-signed certificate');

    expect($exception->getMessage())
        ->toContain('cURL error 60')
        ->toContain('plex.direct');
});

test('connectionFailed leaves other connection failures unchanged', function () {
    $exception = PlexException::connectionFailed('/identity', 'Connection refused');

    expect($exception->getMessage())
        ->toBe('Plex request to [/identity] failed: Connection refused')
        ->not->toContain('plex.direct');
});
