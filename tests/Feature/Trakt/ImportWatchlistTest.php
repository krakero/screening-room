<?php

use App\Actions\Trakt\ImportWatchlist;
use App\Enums\TitleType;
use App\Models\MediaList;
use App\Models\Title;
use App\Services\Trakt\ExportReader;

beforeEach(function () {
    $this->reader = new ExportReader(base_path('tests/Fixtures/trakt/sample'));
    $this->movieOne = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 1001]);
    $this->movieThree = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 1003]);
});

test('it fills the watchlist in rank order', function () {
    $result = (new ImportWatchlist)->handle($this->reader, dryRun: false);

    expect($result->imported)->toBe(2)
        ->and($result->skipped)->toBe(0);

    $watchlist = MediaList::watchlist();

    expect($watchlist->items()->count())->toBe(2)
        ->and($watchlist->items()->orderBy('position')->pluck('title_id')->all())
        ->toBe([$this->movieOne->id, $this->movieThree->id]);
});

test('re-running the import does not duplicate items', function () {
    (new ImportWatchlist)->handle($this->reader, dryRun: false);
    (new ImportWatchlist)->handle($this->reader, dryRun: false);

    expect(MediaList::watchlist()->items()->count())->toBe(2);
});

test('dry run makes no writes', function () {
    $result = (new ImportWatchlist)->handle($this->reader, dryRun: true);

    expect($result->imported)->toBe(2)
        ->and(MediaList::query()->count())->toBe(0);
});
