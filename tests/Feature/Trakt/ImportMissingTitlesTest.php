<?php

use App\Actions\Tmdb\ImportTitle;
use App\Actions\Trakt\DataTransferObjects\TitleReference;
use App\Actions\Trakt\ImportMissingTitles;
use App\Enums\TitleType;
use App\Models\Title;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

function fakeTmdbMovie(int $tmdbId, string $title): array
{
    return ['id' => $tmdbId, 'title' => $title, 'credits' => ['cast' => [], 'crew' => []]];
}

function fakeTmdbShow(int $tmdbId, string $name): array
{
    return [
        'id' => $tmdbId,
        'name' => $name,
        'seasons' => [['season_number' => 1]],
        'aggregate_credits' => ['cast' => [], 'crew' => []],
    ];
}

test('it imports missing titles and skips ones already present', function () {
    Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 1001]);

    Http::fake([
        '*/movie/1003*' => Http::response(fakeTmdbMovie(1003, 'Test Movie Three')),
        '*/tv/2001*' => Http::response(array_merge(
            fakeTmdbShow(2001, 'Test Show One'),
            ['season/1' => ['id' => 9001, 'name' => 'Season 1', 'episodes' => [
                ['id' => 700001, 'episode_number' => 1, 'season_number' => 1],
                ['id' => 700002, 'episode_number' => 2, 'season_number' => 1],
            ]]],
        )),
    ]);

    $references = [
        new TitleReference(TitleType::Movie, 1001),
        new TitleReference(TitleType::Movie, 1003),
        new TitleReference(TitleType::Show, 2001),
    ];

    $progressed = [];
    $result = (new ImportMissingTitles(app(ImportTitle::class)))
        ->handle($references, dryRun: false, onProgress: function (TitleReference $reference) use (&$progressed): void {
            $progressed[] = $reference->key();
        });

    expect($result->imported)->toBe(2)
        ->and($result->alreadyPresent)->toBe(1)
        ->and($result->skipped)->toBe([])
        ->and($progressed)->toBe(['movie:1001', 'movie:1003', 'show:2001']);

    expect(Title::where('type', TitleType::Movie)->where('tmdb_id', 1003)->exists())->toBeTrue();
    expect(Title::where('type', TitleType::Show)->where('tmdb_id', 2001)->exists())->toBeTrue();
});

test('it records a 404 as a skipped reference instead of failing', function () {
    Http::fake([
        '*/movie/1002*' => Http::response(['status_code' => 34, 'status_message' => 'not found'], 404),
    ]);

    $result = (new ImportMissingTitles(app(ImportTitle::class)))
        ->handle([new TitleReference(TitleType::Movie, 1002)], dryRun: false);

    expect($result->imported)->toBe(0)
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->reason)->toBe('not found on TMDB (404)');

    expect(Title::count())->toBe(0);
});

test('dry run makes no network calls or database writes', function () {
    Http::fake();

    $result = (new ImportMissingTitles(app(ImportTitle::class)))
        ->handle([new TitleReference(TitleType::Movie, 1001)], dryRun: true);

    expect($result->imported)->toBe(1)
        ->and(Title::count())->toBe(0);

    Http::assertNothingSent();
});
