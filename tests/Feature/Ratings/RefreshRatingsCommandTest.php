<?php

use App\Console\Commands\RefreshRatings;
use App\Jobs\RefreshTitleRatings;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
});

test('it refreshes a title that has never been checked', function () {
    $title = Title::factory()->movie()->create(['ratings_checked_at' => null]);

    $this->artisan('ratings:refresh')->assertSuccessful();

    Bus::assertDispatched(RefreshTitleRatings::class, fn (RefreshTitleRatings $job): bool => $job->title->is($title));
});

test('it does not refresh a title checked within the last week', function () {
    $title = Title::factory()->movie()->create(['ratings_checked_at' => now()->subDays(2)]);

    $this->artisan('ratings:refresh')->assertSuccessful();

    Bus::assertNotDispatched(RefreshTitleRatings::class, fn (RefreshTitleRatings $job): bool => $job->title->is($title));
});

test('it refreshes a title checked more than a week ago', function () {
    $title = Title::factory()->movie()->create(['ratings_checked_at' => now()->subDays(8)]);

    $this->artisan('ratings:refresh')->assertSuccessful();

    Bus::assertDispatched(RefreshTitleRatings::class, fn (RefreshTitleRatings $job): bool => $job->title->is($title));
});

test('it prioritizes never-checked titles, then the oldest checked', function () {
    $newestStale = Title::factory()->movie()->create(['ratings_checked_at' => now()->subDays(8)]);
    $oldestStale = Title::factory()->movie()->create(['ratings_checked_at' => now()->subDays(30)]);
    $neverChecked = Title::factory()->movie()->create(['ratings_checked_at' => null]);

    $this->artisan('ratings:refresh', ['--limit' => 2])->assertSuccessful();

    Bus::assertDispatched(RefreshTitleRatings::class, fn (RefreshTitleRatings $job): bool => $job->title->is($neverChecked));
    Bus::assertDispatched(RefreshTitleRatings::class, fn (RefreshTitleRatings $job): bool => $job->title->is($oldestStale));
    Bus::assertNotDispatched(RefreshTitleRatings::class, fn (RefreshTitleRatings $job): bool => $job->title->is($newestStale));
});

test('it respects the limit option', function () {
    Title::factory()->count(3)->movie()->create(['ratings_checked_at' => null]);

    $this->artisan('ratings:refresh', ['--limit' => 1])->assertSuccessful();

    Bus::assertDispatchedTimes(RefreshTitleRatings::class, 1);
});

test('it refreshes never-checked titles newest first', function () {
    $older = Title::factory()->movie()->create(['ratings_checked_at' => null, 'created_at' => now()->subDays(3)]);
    $newer = Title::factory()->movie()->create(['ratings_checked_at' => null, 'created_at' => now()->subHour()]);

    $this->artisan('ratings:refresh', ['--limit' => 1])->assertSuccessful();

    Bus::assertDispatchedTimes(RefreshTitleRatings::class, 1);
    Bus::assertDispatched(RefreshTitleRatings::class, fn (RefreshTitleRatings $job): bool => $job->title->is($newer));
    Bus::assertNotDispatched(RefreshTitleRatings::class, fn (RefreshTitleRatings $job): bool => $job->title->is($older));
});

test('it spaces the dispatched jobs out', function () {
    Title::factory()->count(2)->movie()->create(['ratings_checked_at' => null]);

    $this->artisan('ratings:refresh')->assertSuccessful();

    Bus::assertDispatched(RefreshTitleRatings::class, fn (RefreshTitleRatings $job): bool => $job->delay !== null
        && now()->diffInSeconds($job->delay, true) >= RefreshRatings::DISPATCH_SPACING_SECONDS - 1);
});

test('it is scheduled hourly with a limit under the daily cap, only when mdblist is configured', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'ratings:refresh'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->command)->toContain('--limit='.RefreshRatings::HOURLY_LIMIT)
        ->and(RefreshRatings::HOURLY_LIMIT * 24)->toBeLessThan(1000)
        ->and($event->filtersPass(app()))->toBeFalse();

    app(IntegrationSettings::class)->set('mdblist.api_key', 'test-key');

    expect($event->filtersPass(app()))->toBeTrue();
});
