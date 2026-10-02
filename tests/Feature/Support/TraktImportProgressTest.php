<?php

use App\Enums\TitleType;
use App\Models\Title;
use App\Support\IntegrationSettings;
use App\Support\TraktImportProgress;

test('starting writes a running status with totals and the export path/dry-run flag', function () {
    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 25, playsTotal: 2, exportPath: 'imports/export.zip', dryRun: false);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['status'])->toBe('running')
        ->and($lastImport['batch_id'])->toBe('batch-1')
        ->and($lastImport['titles_total'])->toBe(25)
        ->and($lastImport['plays_total'])->toBe(2)
        ->and($lastImport['phase'])->toBe('titles')
        ->and($lastImport['export_path'])->toBe('imports/export.zip')
        ->and($lastImport['dry_run'])->toBeFalse();
});

test('phase updates the current/total for a matching batch id', function () {
    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 25, playsTotal: 2, exportPath: 'imports/export.zip', dryRun: false);
    $progress->phase('batch-1', 'plays', current: 1, total: 2);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['phase'])->toBe('plays')
        ->and($lastImport['current'])->toBe(1)
        ->and($lastImport['total'])->toBe(2);
});

test('phase is ignored for a stale batch id', function () {
    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 25, playsTotal: 2, exportPath: 'imports/export.zip', dryRun: false);
    $progress->phase('a-different-batch', 'plays', current: 1, total: 2);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['phase'])->toBe('titles');
});

test('finished writes the final status for a matching batch id and carries over the export path and failed titles', function () {
    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 25, playsTotal: 2, exportPath: 'imports/export.zip', dryRun: false);
    $progress->titleFailed('batch-1', [['type' => 'movie', 'tmdb_id' => 603]], 'The job failed permanently.');
    $progress->finished('batch-1', 'completed_with_errors', ['titles_total' => 25, 'failed_jobs' => 1]);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['status'])->toBe('completed_with_errors')
        ->and($lastImport['summary'])->toBe(['titles_total' => 25, 'failed_jobs' => 1])
        ->and($lastImport['export_path'])->toBe('imports/export.zip')
        ->and($lastImport['failed_titles'])->toBe(['movie:603' => ['type' => 'movie', 'tmdb_id' => 603, 'error' => 'The job failed permanently.']]);
});

test('retarget repoints a matching batch id, so a later phase/finished call for the new id is accepted', function () {
    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 25, playsTotal: 2, exportPath: 'imports/export.zip', dryRun: false);
    $progress->retarget('batch-1', 'batch-2');
    $progress->phase('batch-2', 'plays', current: 1, total: 2);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['batch_id'])->toBe('batch-2')
        ->and($lastImport['phase'])->toBe('plays')
        ->and($lastImport['status'])->toBe('running');
});

test('retarget is ignored for a stale batch id', function () {
    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 25, playsTotal: 2, exportPath: 'imports/export.zip', dryRun: false);
    $progress->retarget('a-different-batch', 'batch-2');

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['batch_id'])->toBe('batch-1');
});

test('titleFailed records each failed title keyed by type:tmdb_id, replacing a prior entry for the same title', function () {
    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 2, playsTotal: 0, exportPath: 'imports/export.zip', dryRun: false);
    $progress->titleFailed('batch-1', [['type' => 'movie', 'tmdb_id' => 603]], 'Timed out.');
    $progress->titleFailed('batch-1', [['type' => 'movie', 'tmdb_id' => 603]], 'Timed out again.');
    $progress->titleFailed('batch-1', [['type' => 'show', 'tmdb_id' => 1399]], 'Timed out.');

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['failed_titles'])->toBe([
        'movie:603' => ['type' => 'movie', 'tmdb_id' => 603, 'error' => 'Timed out again.'],
        'show:1399' => ['type' => 'show', 'tmdb_id' => 1399, 'error' => 'Timed out.'],
    ]);
});

test('titleFailed is ignored for a stale batch id', function () {
    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 2, playsTotal: 0, exportPath: 'imports/export.zip', dryRun: false);
    $progress->titleFailed('a-different-batch', [['type' => 'movie', 'tmdb_id' => 603]], 'Timed out.');

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['failed_titles'] ?? [])->toBe([]);
});

test('startingRetry repoints the batch id and resets progress while keeping the prior failed titles', function () {
    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 2, playsTotal: 0, exportPath: 'imports/export.zip', dryRun: false);
    $progress->titleFailed('batch-1', [['type' => 'movie', 'tmdb_id' => 603]], 'Timed out.');
    $progress->finished('batch-1', 'completed_with_errors', ['titles_total' => 2, 'failed_jobs' => 1]);

    $progress->startingRetry('retry-batch-1', ['movie:603']);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['batch_id'])->toBe('retry-batch-1')
        ->and($lastImport['status'])->toBe('running')
        ->and($lastImport['total'])->toBe(1)
        ->and($lastImport['failed_titles'])->toHaveKey('movie:603');
});

test('retryFinished drops a retried title that now exists and keeps one that is still failing', function () {
    Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 2, playsTotal: 0, exportPath: 'imports/export.zip', dryRun: false);
    $progress->titleFailed('batch-1', [
        ['type' => 'movie', 'tmdb_id' => 603],
        ['type' => 'show', 'tmdb_id' => 1399],
    ], 'Timed out.');
    $progress->finished('batch-1', 'completed_with_errors', ['titles_total' => 2, 'failed_jobs' => 2]);

    $progress->startingRetry('retry-batch-1', ['movie:603', 'show:1399']);
    $progress->titleFailed('retry-batch-1', [['type' => 'show', 'tmdb_id' => 1399]], 'Timed out again.');
    $progress->retryFinished('retry-batch-1');

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['status'])->toBe('completed_with_errors')
        ->and($lastImport['failed_titles'])->toBe([
            'show:1399' => ['type' => 'show', 'tmdb_id' => 1399, 'error' => 'Timed out again.'],
        ]);
});

test('retryFinished reports success once every retried title resolves', function () {
    Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 1, playsTotal: 0, exportPath: 'imports/export.zip', dryRun: false);
    $progress->titleFailed('batch-1', [['type' => 'movie', 'tmdb_id' => 603]], 'Timed out.');
    $progress->finished('batch-1', 'completed_with_errors', ['titles_total' => 1, 'failed_jobs' => 1]);

    $progress->startingRetry('retry-batch-1', ['movie:603']);
    $progress->retryFinished('retry-batch-1');

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['status'])->toBe('success')
        ->and($lastImport['failed_titles'] ?? [])->toBe([]);
});

test('retryFinished is ignored for a stale batch id', function () {
    $progress = app(TraktImportProgress::class);

    $progress->starting('batch-1', titlesTotal: 1, playsTotal: 0, exportPath: 'imports/export.zip', dryRun: false);
    $progress->titleFailed('batch-1', [['type' => 'movie', 'tmdb_id' => 603]], 'Timed out.');
    $progress->finished('batch-1', 'completed_with_errors', ['titles_total' => 1, 'failed_jobs' => 1]);

    $progress->startingRetry('retry-batch-1', ['movie:603']);
    $progress->retryFinished('a-different-batch');

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['batch_id'])->toBe('retry-batch-1')
        ->and($lastImport['status'])->toBe('running');
});
