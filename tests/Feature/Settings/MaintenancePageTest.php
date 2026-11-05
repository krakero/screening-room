<?php

use App\Jobs\RunMaintenanceTask;
use App\Models\User;
use App\Support\Maintenance\MaintenanceTask;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the maintenance page renders with all tasks', function () {
    Livewire::test('pages::settings.maintenance')
        ->assertSee('Maintenance')
        ->assertSee('Plex library index')
        ->assertSee('TMDB metadata refresh')
        ->assertSee('Rebuild Up Next')
        ->assertSee('Check for updates')
        ->assertSee('Run now');
});

test('run now dispatches the job and marks the task as queued', function () {
    Queue::fake();

    Livewire::test('pages::settings.maintenance')
        ->call('runTask', 'tmdb-refresh');

    Queue::assertPushed(RunMaintenanceTask::class, fn ($job) => $job->task === MaintenanceTask::TmdbRefresh);

    $cached = Cache::get('maintenance.task.tmdb-refresh');
    expect($cached['state'])->toBe('queued')
        ->and($cached['queued_at'])->not->toBeNull();
});

test('run now while already queued does not dispatch a second job', function () {
    Queue::fake();

    Cache::put('maintenance.task.tmdb-refresh', [
        'state' => 'queued',
        'queued_at' => now()->toIso8601String(),
    ]);

    Livewire::test('pages::settings.maintenance')
        ->call('runTask', 'tmdb-refresh');

    Queue::assertNothingPushed();
});

test('run now while running does not dispatch a second job', function () {
    Queue::fake();

    Cache::put('maintenance.task.tmdb-refresh', [
        'state' => 'running',
        'queued_at' => now()->toIso8601String(),
        'started_at' => now()->toIso8601String(),
    ]);

    Livewire::test('pages::settings.maintenance')
        ->call('runTask', 'tmdb-refresh');

    Queue::assertNothingPushed();
});

test('stale queued task (over 2 hours) allows run now', function () {
    Queue::fake();

    Cache::put('maintenance.task.tmdb-refresh', [
        'state' => 'queued',
        'queued_at' => now()->subHours(3)->toIso8601String(),
    ]);

    Livewire::test('pages::settings.maintenance')
        ->call('runTask', 'tmdb-refresh');

    Queue::assertPushed(RunMaintenanceTask::class);
});

test('job success records state as succeeded with output', function () {
    $task = MaintenanceTask::UpdatesCheck;

    Cache::put($task->cacheKey(), [
        'state' => 'queued',
        'queued_at' => now()->toIso8601String(),
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('updates:check', [])
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn("Checking for updates...\nNo updates available\n");

    (new RunMaintenanceTask($task))->handle();

    $cached = Cache::get($task->cacheKey());
    expect($cached['state'])->toBe('succeeded')
        ->and($cached['exit_code'])->toBe(0)
        ->and($cached['finished_at'])->not->toBeNull()
        ->and($cached['output'])->toContain('No updates available');
});

test('job failure with non-zero exit records state as failed with error', function () {
    $task = MaintenanceTask::UpdatesCheck;

    Cache::put($task->cacheKey(), [
        'state' => 'queued',
        'queued_at' => now()->toIso8601String(),
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('updates:check', [])
        ->andReturn(1);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn("Error occurred\n");

    (new RunMaintenanceTask($task))->handle();

    $cached = Cache::get($task->cacheKey());
    expect($cached['state'])->toBe('failed')
        ->and($cached['exit_code'])->toBe(1)
        ->and($cached['error'])->not->toBeNull();
});

test('job failure via exception records state as failed with error message', function () {
    $task = MaintenanceTask::UpdatesCheck;

    Cache::put($task->cacheKey(), [
        'state' => 'queued',
        'queued_at' => now()->toIso8601String(),
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('updates:check', [])
        ->andThrow(new RuntimeException('Connection refused'));

    (new RunMaintenanceTask($task))->handle();

    $cached = Cache::get($task->cacheKey());
    expect($cached['state'])->toBe('failed')
        ->and($cached['error'])->toBe('Connection refused');
});

test('poll element is absent when no task is active', function () {
    Livewire::test('pages::settings.maintenance')
        ->assertDontSee('wire:poll.3s', escape: false);
});

test('poll element is present when a task is queued', function () {
    Cache::put('maintenance.task.tmdb-refresh', [
        'state' => 'queued',
        'queued_at' => now()->toIso8601String(),
    ]);

    Livewire::test('pages::settings.maintenance')
        ->assertSee('wire:poll.3s', escape: false);
});

test('job records skipped when lock is already held', function () {
    $task = MaintenanceTask::UpdatesCheck;

    Cache::put($task->cacheKey(), [
        'state' => 'queued',
        'queued_at' => now()->toIso8601String(),
    ]);

    $lock = Cache::lock("maintenance:{$task->value}", 10);
    $lock->get();

    try {
        (new RunMaintenanceTask($task))->handle();
    } finally {
        $lock->forceRelease();
    }

    $cached = Cache::get($task->cacheKey());
    expect($cached['state'])->toBe('skipped')
        ->and($cached['error'])->toContain('already in progress')
        ->and($cached['finished_at'])->not->toBeNull();
});

test('job records skipped when scheduler mutex is held for the command', function () {
    $task = MaintenanceTask::UpdatesCheck;

    Cache::put($task->cacheKey(), [
        'state' => 'queued',
        'queued_at' => now()->toIso8601String(),
    ]);

    $mutex = Mockery::mock(EventMutex::class);
    $mutex->shouldReceive('exists')->andReturn(true);

    $event = Mockery::mock(Event::class)->makePartial();
    $event->command = "artisan {$task->command()}";
    $event->mutex = $mutex;

    $schedule = Mockery::mock(Schedule::class);
    $schedule->shouldReceive('events')->andReturn([$event]);
    app()->instance(Schedule::class, $schedule);

    (new RunMaintenanceTask($task))->handle();

    $cached = Cache::get($task->cacheKey());
    expect($cached['state'])->toBe('skipped')
        ->and($cached['error'])->toContain('scheduler')
        ->and($cached['finished_at'])->not->toBeNull();
});
