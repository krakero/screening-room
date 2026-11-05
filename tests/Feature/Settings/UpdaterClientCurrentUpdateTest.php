<?php

use App\Services\Updates\UpdaterClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('updater');
});

test('returns null when no files exist', function () {
    $client = new UpdaterClient;

    expect($client->currentUpdate())->toBeNull();
});

test('returns queued when request.json exists', function () {
    Storage::disk('updater')->put('request.json', json_encode([
        'id' => 'req-123',
        'action' => 'update',
        'tag' => 'latest',
        'requested_at' => now()->toIso8601String(),
    ]));

    $client = new UpdaterClient;
    $result = $client->currentUpdate();

    expect($result)->not->toBeNull()
        ->and($result['state'])->toBe('queued')
        ->and($result['id'])->toBe('req-123');
});

test('returns starting when request.processing.json exists and status.json is missing', function () {
    Storage::disk('updater')->put('request.processing.json', json_encode([
        'id' => 'req-123',
        'action' => 'update',
    ]));

    $client = new UpdaterClient;
    $result = $client->currentUpdate();

    expect($result)->not->toBeNull()
        ->and($result['state'])->toBe('starting');
});

test('returns starting when request.processing.json exists and status.json has a different id', function () {
    Storage::disk('updater')->put('request.processing.json', json_encode([
        'id' => 'req-new',
        'action' => 'update',
    ]));

    Storage::disk('updater')->put('status.json', json_encode([
        'id' => 'req-old',
        'state' => 'done',
    ]));

    $client = new UpdaterClient;
    $result = $client->currentUpdate();

    expect($result)->not->toBeNull()
        ->and($result['state'])->toBe('starting');
});

test('returns in-progress status from status.json when request.processing.json id matches', function () {
    Storage::disk('updater')->put('request.processing.json', json_encode([
        'id' => 'req-123',
        'action' => 'update',
    ]));

    Storage::disk('updater')->put('status.json', json_encode([
        'id' => 'req-123',
        'state' => 'pulling',
        'message' => 'Downloading...',
    ]));

    $client = new UpdaterClient;
    $result = $client->currentUpdate();

    expect($result)->not->toBeNull()
        ->and($result['state'])->toBe('pulling')
        ->and($result['message'])->toBe('Downloading...');
});

test('returns in-progress status from status.json', function () {
    Storage::disk('updater')->put('status.json', json_encode([
        'id' => 'req-123',
        'state' => 'recreating',
        'message' => 'Applying containers...',
    ]));

    $client = new UpdaterClient;
    $result = $client->currentUpdate();

    expect($result)->not->toBeNull()
        ->and($result['state'])->toBe('recreating');
});

test('returns recent terminal status with finished_at', function () {
    Storage::disk('updater')->put('status.json', json_encode([
        'id' => 'req-123',
        'state' => 'done',
    ]));

    $client = new UpdaterClient;
    $result = $client->currentUpdate();

    expect($result)->not->toBeNull()
        ->and($result['state'])->toBe('done')
        ->and($result)->toHaveKey('finished_at');
});

test('returns null for stale terminal status', function () {
    Storage::disk('updater')->put('status.json', json_encode([
        'id' => 'req-123',
        'state' => 'done',
    ]));

    Carbon::setTestNow(now()->addMinutes(11));

    $client = new UpdaterClient;
    $result = $client->currentUpdate();

    Carbon::setTestNow();

    expect($result)->toBeNull();
});

test('returns null for stale failed status', function () {
    Storage::disk('updater')->put('status.json', json_encode([
        'id' => 'req-123',
        'state' => 'failed',
    ]));

    Carbon::setTestNow(now()->addMinutes(11));

    $client = new UpdaterClient;
    $result = $client->currentUpdate();

    Carbon::setTestNow();

    expect($result)->toBeNull();
});

test('cancelQueued deletes request.json and returns true', function () {
    Storage::disk('updater')->put('request.json', json_encode(['id' => 'req-123']));

    $client = new UpdaterClient;
    $result = $client->cancelQueued();

    expect($result)->toBeTrue();
    expect(Storage::disk('updater')->exists('request.json'))->toBeFalse();
});

test('cancelQueued returns false when no request.json exists', function () {
    $client = new UpdaterClient;

    expect($client->cancelQueued())->toBeFalse();
});

test('cancelQueued does not delete request.processing.json', function () {
    Storage::disk('updater')->put('request.processing.json', json_encode(['id' => 'req-123']));

    $client = new UpdaterClient;
    $result = $client->cancelQueued();

    expect($result)->toBeFalse();
    expect(Storage::disk('updater')->exists('request.processing.json'))->toBeTrue();
});
