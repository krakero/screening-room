<?php

use App\Actions\Trakt\ImportRatings;
use App\Enums\TitleType;
use App\Models\Rating;
use App\Models\Title;
use App\Services\Trakt\ExportReader;

beforeEach(function () {
    $this->reader = new ExportReader(base_path('tests/Fixtures/trakt/sample'));

    $this->movie = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 1001]);
    $this->show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 2001]);
});

test('it imports ratings as the exact 1-10 Trakt score for titles, skipping unresolved titles and episode ratings', function () {
    $result = app(ImportRatings::class)->handle($this->reader, dryRun: false);

    expect($result->imported)->toBe(2)
        ->and($result->skipped)->toBe(2);

    expect($this->movie->rating->score)->toBe(9)
        ->and($this->show->rating->score)->toBe(7);

    expect(Rating::count())->toBe(2);
});

test('re-running the import replaces the rating rather than duplicating it', function () {
    app(ImportRatings::class)->handle($this->reader, dryRun: false);
    app(ImportRatings::class)->handle($this->reader, dryRun: false);

    expect(Rating::count())->toBe(2);
});

test('dry run makes no writes', function () {
    $result = app(ImportRatings::class)->handle($this->reader, dryRun: true);

    expect($result->imported)->toBe(2)
        ->and(Rating::count())->toBe(0);
});
