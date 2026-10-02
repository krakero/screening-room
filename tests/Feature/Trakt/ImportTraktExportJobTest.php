<?php

use App\Jobs\ImportTraktExport;
use App\Jobs\ImportTraktTitles;
use App\Models\MediaList;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Title;
use App\Services\Stats\StatsCacheVersion;
use App\Services\Trakt\ExportReader;
use App\Services\Trakt\TraktExportException;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function storeTraktZip(callable $build): string
{
    $zipPath = sys_get_temp_dir().'/trakt-export-job-'.uniqid().'.zip';

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $build($zip);
    $zip->close();

    $storedPath = 'imports/'.basename($zipPath);

    Storage::disk('local')->put($storedPath, file_get_contents($zipPath));

    unlink($zipPath);

    return $storedPath;
}

function movieHistoryEntry(int $id, int $tmdbId): array
{
    return [
        'id' => $id,
        'watched_at' => '2024-01-01T00:00:00.000Z',
        'action' => 'scrobble',
        'type' => 'movie',
        'movie' => ['title' => "Movie {$tmdbId}", 'year' => 2021, 'ids' => ['trakt' => $id, 'slug' => "movie-{$tmdbId}", 'imdb' => "tt{$tmdbId}", 'tmdb' => $tmdbId]],
    ];
}

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

afterEach(function () {
    // ImportTraktExport extracts to a persistent working directory under the
    // real `local` disk root (not the fake one), by design, so it survives
    // across the batch's jobs; clean up anything left behind by these tests.
    collect(File::glob(storage_path('app/private/imports/trakt-*')))
        ->each(fn (string $dir) => File::deleteDirectory($dir));
});

test('it batches title imports one job per title, each carrying that title\'s slice of watch-history plays', function () {
    Storage::fake('local');
    Bus::fake();

    $entries = collect(range(1, 25))->map(fn (int $i) => movieHistoryEntry($i, 5000 + $i));

    $storedPath = storeTraktZip(function (ZipArchive $zip) use ($entries): void {
        $zip->addFromString('vmitchell85/watched/history-1.json', json_encode($entries->take(15)->values()->all()));
        $zip->addFromString('vmitchell85/watched/history-2.json', json_encode($entries->skip(15)->values()->all()));
        $zip->addFromString('vmitchell85/lists/watchlist.json', json_encode([]));
    });

    app()->call([new ImportTraktExport($storedPath), 'handle']);

    Bus::assertBatched(function ($batch): bool {
        $titleJobs = collect($batch->jobs)->filter(fn ($job): bool => $job instanceof ImportTraktTitles)->values();

        expect($titleJobs)->toHaveCount(25)
            ->and($titleJobs->map(fn (ImportTraktTitles $job): int => count($job->references))->all())->toBe(array_fill(0, 25, 1))
            ->and($titleJobs->map(fn (ImportTraktTitles $job): int => count(ExportReader::decodeJsonFile($job->playsFile)))->all())->toBe(array_fill(0, 25, 1))
            ->and($titleJobs->map(fn (ImportTraktTitles $job): int => $job->playsProcessedBefore)->all())->toBe(range(0, 24))
            ->and($titleJobs->last()->playsTotal)->toBe(25);

        return true;
    });
});

test('the tail still runs, and the working directory is only deleted once, when the last title job in a batch permanently fails, and the failure is recorded for retry', function () {
    // A real `database` queue + `queue:work` run (rather than `sync`, which
    // re-throws past the batch machinery, or manually invoking `failed()`,
    // which bypasses it) is what actually reproduces the ordering this test
    // guards: `Illuminate\Queue\Worker::process()` records the batch failure
    // (`Batch::recordFailedJob()`, which can trigger `finally()`) before the
    // worker moves on — the same ordering `CallQueuedHandler::failed()` uses
    // for an exception exhausting its retries mid-`handle()`.
    config(['queue.default' => 'database']);

    // Imported plays bump the stats version, which queues a delayed stats warm; once the clock jumps
    // past its delay the step-by-step `--once` workers below would pick it up and shift the sequence.
    app()->instance(StatsCacheVersion::class, new class extends StatsCacheVersion
    {
        public function bump(): void
        {
            Cache::add('stats:version', 1, now()->addYear());
            Cache::increment('stats:version');
        }
    });

    // 3 movies now dispatch as 3 separate `ImportTraktTitles` jobs (one per
    // title); the last-dispatched (and with a single worker, last-to-settle)
    // job is made to permanently fail by letting its `retryUntil()` deadline
    // pass before the worker ever picks it up — Worker::process() then fails
    // it immediately (`markJobAsFailedIfAlreadyExceedsMaxAttempts()`) without
    // calling `handle()` again, deterministically reproducing "a title
    // exhausted its retries" without waiting out real backoff delays.
    $tmdbIds = [7001, 7002, 7003];
    $failingId = 7003;

    Http::fake(collect($tmdbIds)
        ->reject(fn (int $id): bool => $id === $failingId)
        ->mapWithKeys(fn (int $id): array => ["*/movie/{$id}*" => Http::response(['id' => $id, 'title' => "Movie {$id}", 'credits' => ['cast' => [], 'crew' => []]])])
        ->all());

    Storage::fake('local');

    $entries = collect($tmdbIds)->values()->map(fn (int $id, int $index) => movieHistoryEntry($index + 1, $id));

    // `watchlist` (empty) is requested alongside `history` so there's still
    // a non-empty tail — plays are no longer part of it, so a `history`-only
    // import would have nothing left to dispatch as a follow-up batch.
    $storedPath = storeTraktZip(function (ZipArchive $zip) use ($entries): void {
        $zip->addFromString('vmitchell85/watched/history-1.json', json_encode($entries->values()->all()));
        $zip->addFromString('vmitchell85/lists/watchlist.json', json_encode([]));
    });

    $start = Carbon::now();
    Carbon::setTestNow($start);

    try {
        ImportTraktExport::dispatch($storedPath, false, ['history', 'watchlist']);

        // 1: run `ImportTraktExport` itself, which dispatches the three title
        // jobs (all stamped with `retryUntil` = $start + 15 minutes).
        Artisan::call('queue:work', ['--queue' => 'default', '--once' => true, '--sleep' => 0]);

        // 2 & 3: run the first two (successful) title jobs — each imports its
        // title *and* its plays, before the batch has fully settled and
        // before the failing third job even runs.
        Artisan::call('queue:work', ['--queue' => 'default', '--once' => true, '--sleep' => 0]);
        Artisan::call('queue:work', ['--queue' => 'default', '--once' => true, '--sleep' => 0]);

        expect(Title::count())->toBe(2)
            ->and(Play::count())->toBe(2)
            ->and(File::glob(storage_path('app/private/imports/trakt-*')))->not->toBeEmpty();

        // 4: jump past the failing job's `retryUntil` deadline, then run it —
        // the worker fails it immediately, without calling `handle()`, which
        // is what triggers the batch's `finally()` callback (and, via
        // `ImportTraktTitles::failed()`, records the failed title).
        Carbon::setTestNow($start->copy()->addMinutes(20));
        Artisan::call('queue:work', ['--queue' => 'default', '--once' => true, '--sleep' => 0]);

        // The working directory must still exist for the tail (dispatched
        // from that `finally()` callback) to read.
        expect(File::glob(storage_path('app/private/imports/trakt-*')))->not->toBeEmpty();

        // 5: run the tail (`ImportTraktWatchlist`) and its own `finally()`,
        // which is what actually deletes the working directory.
        Artisan::call('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--sleep' => 0]);
    } finally {
        Carbon::setTestNow();
    }

    expect(Title::count())->toBe(2)
        ->and(Play::count())->toBe(2);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['status'])->toBe('completed_with_errors')
        ->and($lastImport['failed_titles'])->toHaveKey('movie:7003')
        ->and($lastImport['export_path'])->toBe($storedPath);

    expect(File::glob(storage_path('app/private/imports/trakt-*')))->toBeEmpty();
});

test('it records a failed status and cleans up the working directory when the export cannot be located', function () {
    Storage::fake('local');

    $storedPath = storeTraktZip(function (ZipArchive $zip): void {
        $zip->addFromString('nothing.txt', 'nothing here');
    });

    expect(fn () => app()->call([new ImportTraktExport($storedPath), 'handle']))
        ->toThrow(TraktExportException::class);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['status'])->toBe('failed')
        ->and($lastImport['error'])->toContain('Could not locate a Trakt export');

    expect(File::glob(storage_path('app/private/imports/trakt-*')))->toBeEmpty();
});

test('it runs the full batched pipeline synchronously end to end and cleans up the working directory', function () {
    config(['queue.default' => 'sync']);

    Http::fake([
        '*/movie/1001*' => Http::response(['id' => 1001, 'title' => 'Test Movie One', 'credits' => ['cast' => [], 'crew' => []]]),
        '*/movie/1002*' => Http::response(['status_message' => 'not found'], 404),
        '*/movie/1003*' => Http::response(['id' => 1003, 'title' => 'Test Movie Three', 'credits' => ['cast' => [], 'crew' => []]]),
        '*/tv/2001*' => Http::response([
            'id' => 2001,
            'name' => 'Test Show One',
            'seasons' => [['season_number' => 1]],
            'aggregate_credits' => ['cast' => [], 'crew' => []],
            'season/1' => ['id' => 9001, 'name' => 'Season 1', 'episodes' => [
                ['id' => 700001, 'episode_number' => 1, 'season_number' => 1],
                ['id' => 700002, 'episode_number' => 2, 'season_number' => 1],
            ]],
        ]),
    ]);

    Storage::fake('local');

    $storedPath = storeTraktZip(function (ZipArchive $zip): void {
        foreach ([
            'watched/history-1.json',
            'ratings/ratings-movies.json',
            'ratings/ratings-shows.json',
            'ratings/ratings-episodes.json',
            'lists/watchlist.json',
            'lists/lists.json',
            'lists/list-9001-top-list.json',
        ] as $relative) {
            $zip->addFile(base_path("tests/Fixtures/trakt/sample/{$relative}"), $relative);
        }
    });

    ImportTraktExport::dispatch($storedPath);

    expect(Title::count())->toBe(3)
        ->and(Play::count())->toBe(3)
        ->and(Rating::count())->toBe(2)
        ->and(MediaList::watchlist()->items()->count())->toBe(2)
        ->and(MediaList::where('slug', 'top-list')->firstOrFail()->items()->count())->toBe(2);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['status'])->toBe('success');

    expect(File::glob(storage_path('app/private/imports/trakt-*')))->toBeEmpty();
});

test('re-running the batched import does not duplicate plays, ratings, watchlist, or list items', function () {
    config(['queue.default' => 'sync']);

    Http::fake([
        '*/movie/1001*' => Http::response(['id' => 1001, 'title' => 'Test Movie One', 'credits' => ['cast' => [], 'crew' => []]]),
        '*/movie/1002*' => Http::response(['status_message' => 'not found'], 404),
        '*/movie/1003*' => Http::response(['id' => 1003, 'title' => 'Test Movie Three', 'credits' => ['cast' => [], 'crew' => []]]),
        '*/tv/2001*' => Http::response([
            'id' => 2001,
            'name' => 'Test Show One',
            'seasons' => [['season_number' => 1]],
            'aggregate_credits' => ['cast' => [], 'crew' => []],
            'season/1' => ['id' => 9001, 'name' => 'Season 1', 'episodes' => [
                ['id' => 700001, 'episode_number' => 1, 'season_number' => 1],
                ['id' => 700002, 'episode_number' => 2, 'season_number' => 1],
            ]],
        ]),
    ]);

    Storage::fake('local');

    $buildZip = function (ZipArchive $zip): void {
        foreach ([
            'watched/history-1.json',
            'ratings/ratings-movies.json',
            'ratings/ratings-shows.json',
            'ratings/ratings-episodes.json',
            'lists/watchlist.json',
            'lists/lists.json',
            'lists/list-9001-top-list.json',
        ] as $relative) {
            $zip->addFile(base_path("tests/Fixtures/trakt/sample/{$relative}"), $relative);
        }
    };

    // Re-uploading the export gives it a fresh stored path (as a real
    // `UploadedFile::store()` upload would), so this isn't exercising
    // `ShouldBeUnique` — it's exercising that the chunked title/plays import
    // and the tail's ratings/watchlist/lists imports are all safe to repeat.
    ImportTraktExport::dispatch(storeTraktZip($buildZip));
    ImportTraktExport::dispatch(storeTraktZip($buildZip));

    expect(Title::count())->toBe(3)
        ->and(Play::count())->toBe(3)
        ->and(Rating::count())->toBe(2)
        ->and(MediaList::watchlist()->items()->count())->toBe(2)
        ->and(MediaList::where('slug', 'top-list')->firstOrFail()->items()->count())->toBe(2);

    $lastImport = app(IntegrationSettings::class)->get('trakt.last_import');

    expect($lastImport['status'])->toBe('success');

    expect(File::glob(storage_path('app/private/imports/trakt-*')))->toBeEmpty();
});
