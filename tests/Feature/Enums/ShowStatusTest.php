<?php

use App\Enums\ShowStatus;

test('returning series maps to ongoing', function () {
    expect(ShowStatus::fromTmdbStatus('Returning Series'))->toBe(ShowStatus::Ongoing);
});

test('in production, planned and pilot map to upcoming', function () {
    expect(ShowStatus::fromTmdbStatus('In Production'))->toBe(ShowStatus::Upcoming)
        ->and(ShowStatus::fromTmdbStatus('Planned'))->toBe(ShowStatus::Upcoming)
        ->and(ShowStatus::fromTmdbStatus('Pilot'))->toBe(ShowStatus::Upcoming);
});

test('ended maps to ended', function () {
    expect(ShowStatus::fromTmdbStatus('Ended'))->toBe(ShowStatus::Ended);
});

test('canceled maps to canceled', function () {
    expect(ShowStatus::fromTmdbStatus('Canceled'))->toBe(ShowStatus::Canceled);
});

test('null and unrecognized statuses map to no chip', function () {
    expect(ShowStatus::fromTmdbStatus(null))->toBeNull()
        ->and(ShowStatus::fromTmdbStatus('Some Unknown Status'))->toBeNull();
});

test('each status has a label and a chip tone', function () {
    foreach (ShowStatus::cases() as $status) {
        expect($status->label())->toBeString()->not->toBeEmpty()
            ->and($status->chipTone())->toBeString()->not->toBeEmpty();
    }
});
