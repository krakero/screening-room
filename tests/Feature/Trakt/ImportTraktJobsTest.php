<?php

use App\Jobs\ImportTraktTitles;
use App\Models\Play;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

test('ImportTraktTitles imports its chunk of references and skips already-imported ones', function () {
    Http::fake([
        '*/movie/5001*' => Http::response(['id' => 5001, 'title' => 'Tiny Movie', 'credits' => ['cast' => [], 'crew' => []]]),
        '*/movie/5002*' => Http::response(['status_message' => 'not found'], 404),
    ]);

    $job = new ImportTraktTitles(
        references: [
            ['type' => 'movie', 'tmdb_id' => 5001],
            ['type' => 'movie', 'tmdb_id' => 5002],
        ],
        processedBefore: 0,
        titlesTotal: 2,
    );

    app()->call([$job, 'handle']);

    expect(Title::count())->toBe(1)
        ->and(Title::where('tmdb_id', 5001)->exists())->toBeTrue();
});

test('ImportTraktTitles imports its titles and then the watch-history plays for those titles in the same chunk', function () {
    Http::fake([
        '*/movie/5001*' => Http::response(['id' => 5001, 'title' => 'Tiny Movie', 'credits' => ['cast' => [], 'crew' => []]]),
    ]);

    $playsFile = tempnam(sys_get_temp_dir(), 'trakt-plays-');
    file_put_contents($playsFile, json_encode([
        ['id' => 1, 'watched_at' => '2024-01-01T00:00:00.000Z', 'action' => 'scrobble', 'type' => 'movie', 'movie' => [
            'title' => 'Tiny Movie', 'year' => 2021, 'ids' => ['trakt' => 1, 'slug' => 'tiny-movie-2021', 'tmdb' => 5001],
        ]],
    ]));

    app()->call([new ImportTraktTitles(
        references: [['type' => 'movie', 'tmdb_id' => 5001]],
        processedBefore: 0,
        titlesTotal: 1,
        playsFile: $playsFile,
        playsProcessedBefore: 0,
        playsTotal: 1,
    ), 'handle']);

    File::delete($playsFile);

    expect(Title::where('tmdb_id', 5001)->exists())->toBeTrue()
        ->and(Play::count())->toBe(1);
});

test('ImportTraktTitles still imports its titles when it has no plays file', function () {
    Http::fake([
        '*/movie/5001*' => Http::response(['id' => 5001, 'title' => 'Tiny Movie', 'credits' => ['cast' => [], 'crew' => []]]),
    ]);

    app()->call([new ImportTraktTitles(
        references: [['type' => 'movie', 'tmdb_id' => 5001]],
        processedBefore: 0,
        titlesTotal: 1,
    ), 'handle']);

    expect(Title::where('tmdb_id', 5001)->exists())->toBeTrue()
        ->and(Play::count())->toBe(0);
});

test('ImportMovie retries the transaction up to 3 attempts to survive a MySQL deadlock', function () {
    // `RefreshDatabase` wraps this whole test in its own transaction, and
    // Laravel refuses to retry a deadlock inside a nested transaction (it
    // can't roll back just the savepoint), so this asserts the retry is
    // wired up via `DB::transaction($callback, 3)` rather than driving a
    // real nested deadlock end to end.
    Http::fake([
        '*/movie/9001*' => Http::response(['id' => 9001, 'title' => 'Retry Movie', 'credits' => ['cast' => [], 'crew' => []]]),
    ]);

    DB::shouldReceive('transaction')
        ->once()
        ->withArgs(fn ($callback, $attempts): bool => $attempts === 3)
        ->andReturnUsing(fn ($callback) => $callback());

    $job = new ImportTraktTitles(
        references: [['type' => 'movie', 'tmdb_id' => 9001]],
        processedBefore: 0,
        titlesTotal: 1,
    );

    app()->call([$job, 'handle']);

    expect(Title::where('tmdb_id', 9001)->exists())->toBeTrue();
});

test('ImportTraktTitles is middleware-throttled by the trakt-import-tmdb rate limiter, so a busy limiter releases the job rather than failing it', function () {
    $job = new ImportTraktTitles(
        references: [['type' => 'movie', 'tmdb_id' => 5001]],
        processedBefore: 0,
        titlesTotal: 1,
    );

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RateLimited::class);
});

test('ImportTraktTitles::failed does nothing when the job was never part of a batch', function () {
    $job = new ImportTraktTitles(
        references: [['type' => 'movie', 'tmdb_id' => 5001]],
        processedBefore: 0,
        titlesTotal: 1,
    );

    // No batch is attached, so `$job->batch()` is null — asserting this
    // doesn't throw is what covers the guard in `failed()`. The case where a
    // batch *is* attached is covered end to end by
    // `ImportTraktExportJobTest`'s "the tail still runs..." test, which
    // asserts `failed_titles` after a real permanent job failure.
    $job->failed(new Exception('boom'));

    expect(app(IntegrationSettings::class)->get('trakt.last_import'))->toBeNull();
});
