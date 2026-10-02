<?php

use App\Enums\UpdateChannel;

test('stable channel has correct tag', function () {
    expect(UpdateChannel::Stable->tag())->toBe('latest');
});

test('develop channel has correct tag', function () {
    expect(UpdateChannel::Develop->tag())->toBe('develop');
});

test('stable channel has correct label', function () {
    expect(UpdateChannel::Stable->label())->toBe('Stable');
});

test('develop channel has correct label', function () {
    expect(UpdateChannel::Develop->label())->toBe('Develop');
});

test('can create channel from string value', function () {
    expect(UpdateChannel::from('stable'))->toBe(UpdateChannel::Stable);
    expect(UpdateChannel::from('develop'))->toBe(UpdateChannel::Develop);
});
