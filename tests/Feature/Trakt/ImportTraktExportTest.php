<?php

use App\Actions\Trakt\ImportTraktExport;
use App\Models\MediaList;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Title;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();

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
});

test('it performs a full import end to end', function () {
    $summary = app(ImportTraktExport::class)->handle(base_path('tests/Fixtures/trakt/sample'), dryRun: false);

    expect($summary->titles->imported)->toBe(3)
        ->and($summary->titles->skipped)->toHaveCount(2) // the missing-tmdb-id history entry + the 404'd movie
        ->and($summary->plays->imported)->toBe(3)
        ->and($summary->ratings->imported)->toBe(2)
        ->and($summary->watchlist->imported)->toBe(2)
        ->and($summary->lists->lists)->toBe(1)
        ->and($summary->lists->itemsImported)->toBe(2);

    expect(Title::count())->toBe(3)
        ->and(Play::count())->toBe(3)
        ->and(Rating::count())->toBe(2)
        ->and(MediaList::watchlist()->items()->count())->toBe(2)
        ->and(MediaList::where('slug', 'top-list')->firstOrFail()->items()->count())->toBe(2);
});

test('dry run reports counts without writing anything', function () {
    $summary = app(ImportTraktExport::class)->handle(base_path('tests/Fixtures/trakt/sample'), dryRun: true);

    expect($summary->dryRun)->toBeTrue()
        ->and($summary->titles->imported)->toBe(4);

    Http::assertNothingSent();

    expect(Title::count())->toBe(0)
        ->and(Play::count())->toBe(0)
        ->and(Rating::count())->toBe(0)
        ->and(MediaList::count())->toBe(0);
});

test('--only restricts which sections are collected and imported', function () {
    $summary = app(ImportTraktExport::class)->handle(base_path('tests/Fixtures/trakt/sample'), dryRun: false, only: ['watchlist']);

    expect($summary->plays->imported)->toBe(0)
        ->and($summary->ratings->imported)->toBe(0)
        ->and($summary->lists->lists)->toBe(0)
        ->and($summary->watchlist->imported)->toBe(2);

    // Only the two titles referenced by the watchlist were imported (1001, 1003), not 2001.
    expect(Title::count())->toBe(2)
        ->and(Title::where('tmdb_id', 2001)->exists())->toBeFalse();
});

test('running the import twice is idempotent', function () {
    app(ImportTraktExport::class)->handle(base_path('tests/Fixtures/trakt/sample'), dryRun: false);
    app(ImportTraktExport::class)->handle(base_path('tests/Fixtures/trakt/sample'), dryRun: false);

    expect(Title::count())->toBe(3)
        ->and(Play::count())->toBe(3)
        ->and(Rating::count())->toBe(2)
        ->and(MediaList::watchlist()->items()->count())->toBe(2);
});
