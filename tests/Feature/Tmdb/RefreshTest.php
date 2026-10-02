<?php

use App\Enums\TitleType;
use App\Jobs\ImportTitle;
use App\Jobs\RefreshTitleFromTmdb;
use App\Models\Title;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
});

test('it refreshes an airing show synced more than a day ago', function () {
    $stale = Title::factory()->create([
        'type' => TitleType::Show,
        'in_production' => true,
        'tmdb_synced_at' => now()->subDays(2),
    ]);

    $fresh = Title::factory()->create([
        'type' => TitleType::Show,
        'in_production' => true,
        'tmdb_synced_at' => now()->subHours(2),
    ]);

    $this->artisan('tmdb:refresh')->assertSuccessful();

    Bus::assertDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->type === $stale->type && $job->tmdbId === $stale->tmdb_id);
    Bus::assertNotDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $fresh->tmdb_id);
});

test('it refreshes a returning series status show synced more than a day ago', function () {
    $stale = Title::factory()->create([
        'type' => TitleType::Show,
        'in_production' => false,
        'status' => 'Returning Series',
        'tmdb_synced_at' => now()->subDays(2),
    ]);

    $this->artisan('tmdb:refresh')->assertSuccessful();

    Bus::assertDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $stale->tmdb_id);
});

test('it does not refresh a non-airing show synced within a day', function () {
    $title = Title::factory()->create([
        'type' => TitleType::Show,
        'in_production' => false,
        'status' => 'Ended',
        'tmdb_synced_at' => now()->subHours(12),
    ]);

    $this->artisan('tmdb:refresh')->assertSuccessful();

    Bus::assertNotDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $title->tmdb_id);
});

test('it refreshes a movie or ended show synced more than a week ago', function () {
    $movie = Title::factory()->create([
        'type' => TitleType::Movie,
        'tmdb_synced_at' => now()->subDays(8),
    ]);

    $endedShow = Title::factory()->create([
        'type' => TitleType::Show,
        'in_production' => false,
        'status' => 'Ended',
        'tmdb_synced_at' => now()->subDays(8),
    ]);

    $this->artisan('tmdb:refresh')->assertSuccessful();

    Bus::assertDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $movie->tmdb_id);
    Bus::assertDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $endedShow->tmdb_id);
});

test('it refreshes titles that have never been synced', function () {
    $title = Title::factory()->create([
        'type' => TitleType::Movie,
        'tmdb_synced_at' => null,
    ]);

    $this->artisan('tmdb:refresh')->assertSuccessful();

    Bus::assertDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $title->tmdb_id);
});

test('it does not refresh a movie synced within a week', function () {
    $title = Title::factory()->create([
        'type' => TitleType::Movie,
        'tmdb_synced_at' => now()->subDays(2),
    ]);

    $this->artisan('tmdb:refresh')->assertSuccessful();

    Bus::assertNotDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $title->tmdb_id);
});

test('it prioritizes airing shows and stops at the limit', function () {
    $airing = Title::factory()->count(2)->create([
        'type' => TitleType::Show,
        'in_production' => true,
        'tmdb_synced_at' => now()->subDays(2),
    ]);

    $movie = Title::factory()->create([
        'type' => TitleType::Movie,
        'tmdb_synced_at' => now()->subDays(8),
    ]);

    $this->artisan('tmdb:refresh', ['--limit' => 2])->assertSuccessful();

    foreach ($airing as $title) {
        Bus::assertDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $title->tmdb_id);
    }

    Bus::assertNotDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $movie->tmdb_id);
});

test('it refreshes the oldest stale titles first within the limit, never-synced first', function () {
    $newestStale = Title::factory()->create([
        'type' => TitleType::Movie,
        'tmdb_synced_at' => now()->subDays(8),
    ]);

    $oldestStale = Title::factory()->create([
        'type' => TitleType::Movie,
        'tmdb_synced_at' => now()->subDays(30),
    ]);

    $neverSynced = Title::factory()->create([
        'type' => TitleType::Movie,
        'tmdb_synced_at' => null,
    ]);

    $this->artisan('tmdb:refresh', ['--limit' => 2])->assertSuccessful();

    Bus::assertDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $neverSynced->tmdb_id);
    Bus::assertDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $oldestStale->tmdb_id);
    Bus::assertNotDispatched(ImportTitle::class, fn (ImportTitle $job): bool => $job->tmdbId === $newestStale->tmdb_id);
});

test('--title force-refreshes one title by internal id, ignoring staleness and --limit', function () {
    $fresh = Title::factory()->create([
        'type' => TitleType::Movie,
        'tmdb_synced_at' => now(),
    ]);

    $this->artisan('tmdb:refresh', ['--title' => (string) $fresh->id])->assertSuccessful();

    Bus::assertNotDispatched(ImportTitle::class);
    Bus::assertDispatched(RefreshTitleFromTmdb::class, fn (RefreshTitleFromTmdb $job): bool => $job->titleId === $fresh->id);
});

test('--title falls back to matching by tmdb_id when no title has that internal id', function () {
    $fresh = Title::factory()->create([
        'type' => TitleType::Movie,
        'tmdb_id' => 999999,
        'tmdb_synced_at' => now(),
    ]);

    $this->artisan('tmdb:refresh', ['--title' => '999999'])->assertSuccessful();

    Bus::assertDispatched(RefreshTitleFromTmdb::class, fn (RefreshTitleFromTmdb $job): bool => $job->titleId === $fresh->id);
});

test('--title fails when no title matches the given id or tmdb_id', function () {
    $this->artisan('tmdb:refresh', ['--title' => '999999'])->assertFailed();

    Bus::assertNotDispatched(RefreshTitleFromTmdb::class);
});
