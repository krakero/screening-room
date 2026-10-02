<?php

use App\Actions\Trakt\CollectTitleReferences;
use App\Actions\Trakt\ImportTraktExport;
use App\Enums\TitleType;
use App\Services\Trakt\ExportReader;

test('it collects the unique tmdb references across all sections', function () {
    $reader = new ExportReader(base_path('tests/Fixtures/trakt/sample'));

    $result = (new CollectTitleReferences)->handle($reader, ImportTraktExport::SECTIONS);

    $keys = collect($result['references'])->map->key()->sort()->values()->all();

    expect($keys)->toBe([
        'movie:1001',
        'movie:1002',
        'movie:1003',
        'show:2001',
    ])->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0]->reason)->toBe('missing tmdb id');
});

test('it only scans the requested sections', function () {
    $reader = new ExportReader(base_path('tests/Fixtures/trakt/sample'));

    $result = (new CollectTitleReferences)->handle($reader, ['watchlist']);

    $keys = collect($result['references'])->map->key()->sort()->values()->all();

    expect($keys)->toBe(['movie:1001', 'movie:1003'])
        ->and($result['references'][0]->type)->toBe(TitleType::Movie);
});
