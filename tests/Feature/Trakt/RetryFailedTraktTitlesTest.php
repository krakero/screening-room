<?php

use App\Jobs\RetryFailedTraktTitles;
use App\Models\Play;
use App\Models\Title;
use App\Support\IntegrationSettings;
use App\Support\TraktImportProgress;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function storeRetryTraktZip(callable $build): string
{
    $zipPath = sys_get_temp_dir().'/trakt-retry-'.uniqid().'.zip';

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $build($zip);
    $zip->close();

    $storedPath = 'imports/'.basename($zipPath);

    Storage::disk('local')->put($storedPath, file_get_contents($zipPath));

    unlink($zipPath);

    return $storedPath;
}

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);
    config(['queue.default' => 'sync']);

    Http::preventStrayRequests();
});

afterEach(function () {
    collect(File::glob(storage_path('app/private/imports/trakt-retry-*')))
        ->each(fn (string $dir) => File::deleteDirectory($dir));
});

test('it imports only the given titles and their plays, and removes them from failed_titles once resolved', function () {
    Http::fake([
        '*/movie/8001*' => Http::response(['id' => 8001, 'title' => 'Retry Movie', 'credits' => ['cast' => [], 'crew' => []]]),
    ]);

    Storage::fake('local');

    $storedPath = storeRetryTraktZip(function (ZipArchive $zip): void {
        $zip->addFromString('vmitchell85/watched/history-1.json', json_encode([
            [
                'id' => 1,
                'watched_at' => '2024-01-01T00:00:00.000Z',
                'action' => 'scrobble',
                'type' => 'movie',
                'movie' => ['title' => 'Retry Movie', 'year' => 2021, 'ids' => ['trakt' => 1, 'slug' => 'retry-movie', 'tmdb' => 8001]],
            ],
            // A different title's play, which the retry should leave alone.
            [
                'id' => 2,
                'watched_at' => '2024-01-02T00:00:00.000Z',
                'action' => 'scrobble',
                'type' => 'movie',
                'movie' => ['title' => 'Other Movie', 'year' => 2021, 'ids' => ['trakt' => 2, 'slug' => 'other-movie', 'tmdb' => 8002]],
            ],
        ]));
    });

    app(TraktImportProgress::class)->starting('batch-1', titlesTotal: 1, playsTotal: 1, exportPath: $storedPath, dryRun: false);
    app(TraktImportProgress::class)->titleFailed('batch-1', [['type' => 'movie', 'tmdb_id' => 8001]], 'Timed out.');
    app(TraktImportProgress::class)->finished('batch-1', 'completed_with_errors', ['titles_total' => 1, 'failed_jobs' => 1]);

    app()->call([new RetryFailedTraktTitles($storedPath, [['type' => 'movie', 'tmdb_id' => 8001]]), 'handle']);

    expect(Title::where('tmdb_id', 8001)->exists())->toBeTrue()
        ->and(Title::where('tmdb_id', 8002)->exists())->toBeFalse()
        ->and(Play::count())->toBe(1);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['status'])->toBe('success')
        ->and($lastImport['failed_titles'] ?? [])->toBe([]);

    expect(File::glob(storage_path('app/private/imports/trakt-retry-*')))->toBeEmpty();
});

test('a title that fails again on retry stays in failed_titles with the new error', function () {
    Http::fake([
        '*/movie/8003*' => Http::response(['status_message' => 'Internal error'], 500),
    ]);

    Storage::fake('local');

    $storedPath = storeRetryTraktZip(function (ZipArchive $zip): void {
        $zip->addFromString('vmitchell85/watched/history-1.json', json_encode([]));
    });

    app(TraktImportProgress::class)->starting('batch-1', titlesTotal: 1, playsTotal: 0, exportPath: $storedPath, dryRun: false);
    app(TraktImportProgress::class)->titleFailed('batch-1', [['type' => 'movie', 'tmdb_id' => 8003]], 'Timed out.');
    app(TraktImportProgress::class)->finished('batch-1', 'completed_with_errors', ['titles_total' => 1, 'failed_jobs' => 1]);

    // A TMDB error `ImportMissingTitles` catches internally doesn't fail the
    // job (see `ImportTraktJobsTest`), so the title stays absent but the
    // retry batch itself still reports success from the job's point of
    // view — `retryFinished()` only drops titles that now exist.
    app()->call([new RetryFailedTraktTitles($storedPath, [['type' => 'movie', 'tmdb_id' => 8003]]), 'handle']);

    expect(Title::where('tmdb_id', 8003)->exists())->toBeFalse();

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['status'])->toBe('completed_with_errors')
        ->and($lastImport['failed_titles'])->toHaveKey('movie:8003');
});
