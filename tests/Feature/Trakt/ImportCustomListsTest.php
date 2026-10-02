<?php

use App\Actions\Trakt\ImportCustomLists;
use App\Enums\TitleType;
use App\Models\MediaList;
use App\Models\Title;
use App\Services\Trakt\ExportReader;

beforeEach(function () {
    $this->reader = new ExportReader(base_path('tests/Fixtures/trakt/sample'));
    $this->movie = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 1001]);
    $this->show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 2001]);
});

test('it creates a media list per custom list, preserving rank order', function () {
    $result = (new ImportCustomLists)->handle($this->reader, dryRun: false);

    expect($result->lists)->toBe(1)
        ->and($result->itemsImported)->toBe(2)
        ->and($result->itemsSkipped)->toBe(0);

    $list = MediaList::where('slug', 'top-list')->firstOrFail();

    expect($list->name)->toBe('Top List')
        ->and($list->description)->toBe('A short test list')
        ->and($list->is_watchlist)->toBeFalse()
        ->and($list->items()->orderBy('position')->pluck('title_id')->all())
        ->toBe([$this->movie->id, $this->show->id]);
});

test('re-running the import does not duplicate the list or its items', function () {
    (new ImportCustomLists)->handle($this->reader, dryRun: false);
    (new ImportCustomLists)->handle($this->reader, dryRun: false);

    expect(MediaList::count())->toBe(1)
        ->and(MediaList::where('slug', 'top-list')->firstOrFail()->items()->count())->toBe(2);
});

test('dry run makes no writes', function () {
    $result = (new ImportCustomLists)->handle($this->reader, dryRun: true);

    expect($result->lists)->toBe(1)
        ->and($result->itemsImported)->toBe(2)
        ->and(MediaList::count())->toBe(0);
});
