<?php

namespace App\Jobs;

use App\Support\Maintenance\MaintenanceTask;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunMaintenanceTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly MaintenanceTask $task) {}

    public function handle(): void
    {
        // Queue worker runs in console context, so Schedule events are loaded here (unlike HTTP).
        if ($this->isSchedulerRunning()) {
            $this->updateCache([
                'state' => 'skipped',
                'finished_at' => now()->toIso8601String(),
                'error' => 'Already running from the scheduler.',
            ]);

            return;
        }

        $lock = Cache::lock("maintenance:{$this->task->value}", 7200);

        if (! $lock->get()) {
            $this->updateCache([
                'state' => 'skipped',
                'finished_at' => now()->toIso8601String(),
                'error' => 'Another run of this task is already in progress.',
            ]);

            return;
        }

        try {
            $this->updateCache(['state' => 'running', 'started_at' => now()->toIso8601String()]);

            $exitCode = Artisan::call($this->task->command(), $this->task->arguments());
            $output = Artisan::output();

            $lines = array_values(array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== ''));
            $tail = implode("\n", array_slice($lines, -20));

            $this->updateCache([
                'state' => $exitCode === 0 ? 'succeeded' : 'failed',
                'finished_at' => now()->toIso8601String(),
                'exit_code' => $exitCode,
                'error' => $exitCode !== 0 ? "Command exited with code {$exitCode}." : null,
                'output' => $tail,
            ]);
        } catch (Throwable $e) {
            $this->updateCache([
                'state' => 'failed',
                'finished_at' => now()->toIso8601String(),
                'exit_code' => 1,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $e): void
    {
        $this->updateCache([
            'state' => 'failed',
            'finished_at' => now()->toIso8601String(),
            'exit_code' => 1,
            'error' => $e->getMessage(),
        ]);
    }

    private function isSchedulerRunning(): bool
    {
        try {
            foreach (app(Schedule::class)->events() as $event) {
                if (
                    str_contains((string) $event->command, $this->task->command())
                    && $event->mutex->exists($event)
                ) {
                    return true;
                }
            }
        } catch (Throwable) {
            // Best-effort check
        }

        return false;
    }

    /** @param array<string, mixed> $fields */
    private function updateCache(array $fields): void
    {
        $existing = Cache::get($this->task->cacheKey(), []);

        Cache::put($this->task->cacheKey(), array_merge($existing, $fields), now()->addDays(30));
    }
}
