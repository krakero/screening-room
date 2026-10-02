<?php

use App\Enums\UpdateChannel;
use App\Services\Updates\VersionInfo;

test('version info can be constructed with all properties', function () {
    $info = new VersionInfo(
        version: '1.0.0',
        channel: UpdateChannel::Stable,
        commit: 'abc123'
    );

    expect($info->version)->toBe('1.0.0')
        ->and($info->channel)->toBe(UpdateChannel::Stable)
        ->and($info->commit)->toBe('abc123');
});

test('version info can be constructed without commit', function () {
    $info = new VersionInfo(
        version: 'dev',
        channel: UpdateChannel::Develop
    );

    expect($info->version)->toBe('dev')
        ->and($info->channel)->toBe(UpdateChannel::Develop)
        ->and($info->commit)->toBeNull();
});

test('isDevelop returns true for develop channel', function () {
    $info = new VersionInfo('dev', UpdateChannel::Develop);

    expect($info->isDevelop())->toBeTrue();
});

test('isDevelop returns false for stable channel', function () {
    $info = new VersionInfo('1.0.0', UpdateChannel::Stable);

    expect($info->isDevelop())->toBeFalse();
});
