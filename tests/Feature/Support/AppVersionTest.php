<?php

use App\Enums\UpdateChannel;
use App\Support\AppVersion;

test('current returns version info from config', function () {
    config(['app.version' => '1.0.0']);
    config(['app.channel' => 'stable']);
    config(['app.commit' => 'abc123']);

    $info = AppVersion::current();

    expect($info->version)->toBe('1.0.0')
        ->and($info->channel)->toBe(UpdateChannel::Stable)
        ->and($info->commit)->toBe('abc123');
});

test('current handles develop channel', function () {
    config(['app.version' => 'develop-abc123']);
    config(['app.channel' => 'develop']);
    config(['app.commit' => 'abc123']);

    $info = AppVersion::current();

    expect($info->version)->toBe('develop-abc123')
        ->and($info->channel)->toBe(UpdateChannel::Develop)
        ->and($info->commit)->toBe('abc123')
        ->and($info->isDevelop())->toBeTrue();
});

test('current handles null commit', function () {
    config(['app.version' => 'dev']);
    config(['app.channel' => 'develop']);
    config(['app.commit' => null]);

    $info = AppVersion::current();

    expect($info->commit)->toBeNull();
});
