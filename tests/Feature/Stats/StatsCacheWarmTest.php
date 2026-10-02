<?php

use App\Jobs\WarmStatsCache;
use App\Models\Play;
use App\Models\Title;
use App\Services\Stats\StatsCacheVersion;
use App\Services\Stats\StatsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('bumping the stats version queues a delayed warm job', function () {
    Queue::fake();

    app(StatsCacheVersion::class)->bump();

    Queue::assertPushed(WarmStatsCache::class, fn (WarmStatsCache $job): bool => $job->delay !== null);
});

test('a burst of bumps queues one warm job', function () {
    expect(WarmStatsCache::class)->toImplement(ShouldBeUnique::class);

    Queue::fake();

    $version = app(StatsCacheVersion::class);
    $version->bump();
    $version->bump();

    Queue::assertPushed(WarmStatsCache::class, 1);
});

test('the warm job fills the exact caches the page reads', function () {
    Queue::fake();

    $movie = Title::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);

    (new WarmStatsCache)->handle(app(StatsService::class));

    DB::enableQueryLog();
    $service = app(StatsService::class);
    $summary = $service->summary();
    $years = $service->availableYears();

    expect(DB::getQueryLog())->toBeEmpty()
        ->and($summary['headline']['moviesWatched'])->toBe(1)
        ->and($years)->toBe([(int) now()->format('Y')]);
});
