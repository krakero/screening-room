<?php

use App\Enums\TorrentState;

test('every raw qBittorrent state maps to a grouped state', function (string $raw, TorrentState $expected) {
    expect(TorrentState::fromApi($raw))->toBe($expected);
})->with([
    ['downloading', TorrentState::Downloading],
    ['forcedDL', TorrentState::Downloading],
    ['metaDL', TorrentState::Downloading],
    ['forcedMetaDL', TorrentState::Downloading],
    ['allocating', TorrentState::Downloading],
    ['uploading', TorrentState::Seeding],
    ['forcedUP', TorrentState::Seeding],
    ['stalledUP', TorrentState::Seeding],
    ['pausedDL', TorrentState::Paused],
    ['stoppedDL', TorrentState::Paused],
    ['pausedUP', TorrentState::Completed],
    ['stoppedUP', TorrentState::Completed],
    ['queuedDL', TorrentState::Queued],
    ['queuedUP', TorrentState::Queued],
    ['stalledDL', TorrentState::Stalled],
    ['checkingDL', TorrentState::Checking],
    ['checkingUP', TorrentState::Checking],
    ['checkingResumeData', TorrentState::Checking],
    ['moving', TorrentState::Checking],
    ['error', TorrentState::Error],
    ['missingFiles', TorrentState::Error],
    ['somethingNew', TorrentState::Unknown],
]);

test('every state has a label and colour', function () {
    foreach (TorrentState::cases() as $state) {
        expect($state->label())->not->toBeEmpty()
            ->and($state->color())->not->toBeEmpty();
    }
});
