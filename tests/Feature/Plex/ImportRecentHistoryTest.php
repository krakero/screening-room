<?php

use App\Actions\Plex\ImportRecentHistory;
use App\Enums\TitleType;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

function importFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/plex/{$name}.json")), true);
}

function recentImportHistoryFixture(): array
{
    $history = importFixture('history_all');

    foreach ($history['MediaContainer']['Metadata'] as $index => $item) {
        $history['MediaContainer']['Metadata'][$index]['viewedAt'] = Carbon::now()->subMinutes(5 + $index)->timestamp;
    }

    return $history;
}

test('it returns a zeroed summary when plex is not configured', function () {
    $summary = app(ImportRecentHistory::class)->handle(Carbon::now()->subMinutes(20));

    expect($summary)->toBe([
        'fetched' => 0,
        'recorded' => 0,
        'duplicates' => 0,
        'ignored_account' => 0,
        'unmatched' => 0,
        'unmatched_items' => [],
    ]);
});

test('it summarizes recorded, duplicate, and unmatched items', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
    ]);

    $movie = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1399]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response(recentImportHistoryFixture()),
        '*plex.local:32400/library/metadata/501*' => Http::response(importFixture('metadata_movie')),
        '*plex.local:32400/library/metadata/800*' => Http::response(importFixture('metadata_show')),
    ]);

    $summary = app(ImportRecentHistory::class)->handle(Carbon::now()->subMinutes(20));

    expect($summary['fetched'])->toBe(2)
        ->and($summary['recorded'])->toBe(2)
        ->and($summary['duplicates'])->toBe(0)
        ->and($summary['unmatched'])->toBe(0)
        ->and(Play::where('playable_type', 'title')->where('playable_id', $movie->id)->exists())->toBeTrue()
        ->and(Play::where('playable_type', 'episode')->where('playable_id', $episode->id)->exists())->toBeTrue();
});

test('a dry run resolves matches without recording any plays', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
    ]);

    Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1399]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response(recentImportHistoryFixture()),
        '*plex.local:32400/library/metadata/501*' => Http::response(importFixture('metadata_movie')),
        '*plex.local:32400/library/metadata/800*' => Http::response(importFixture('metadata_show')),
    ]);

    $summary = app(ImportRecentHistory::class)->handle(Carbon::now()->subMinutes(20), dryRun: true);

    expect($summary['recorded'])->toBe(2)
        ->and(Play::count())->toBe(0);
});

test('it reports unmatched items with a reason label', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
    ]);

    $noGuids = ['MediaContainer' => ['Metadata' => [['ratingKey' => '000']]]];

    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response(recentImportHistoryFixture()),
        '*plex.local:32400/library/metadata/501*' => Http::response($noGuids),
        '*plex.local:32400/library/metadata/800*' => Http::response($noGuids),
    ]);

    $summary = app(ImportRecentHistory::class)->handle(Carbon::now()->subMinutes(20));

    expect($summary['unmatched'])->toBe(2)
        ->and($summary['unmatched_items'])->toHaveCount(2)
        ->and($summary['unmatched_items'][0])->toContain('The Matrix')
        ->and($summary['unmatched_items'][1])->toContain('Game of Thrones');
});
