<?php

use App\Support\IntegrationSettings;
use App\Support\PrimaryNavigation;

test('items excludes downloads when qbittorrent is not configured', function () {
    $routes = array_column(PrimaryNavigation::items(), 'route');

    expect($routes)->toBe(['dashboard', 'discover', 'calendar', 'history', 'lists.index', 'stats']);
});

test('items appends downloads last when qbittorrent is configured', function () {
    app(IntegrationSettings::class)->set('qbittorrent.url', 'http://qbit.test:8080');

    $routes = array_column(PrimaryNavigation::items(), 'route');

    expect($routes)->toBe(['dashboard', 'discover', 'calendar', 'history', 'lists.index', 'stats', 'downloads']);
});
