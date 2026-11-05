<?php

use App\Jobs\RunMaintenanceTask;
use App\Support\IntegrationSettings;
use App\Support\Maintenance\MaintenanceTask;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Maintenance')] class extends Component
{
    public bool $plexConfigured = false;

    public function mount(IntegrationSettings $settings): void
    {
        $this->plexConfigured = $settings->configured('plex.url', 'plex.token');
    }

    /** @return list<array<string, mixed>> */
    public function getTasksProperty(): array
    {
        return collect(MaintenanceTask::cases())
            ->map(fn (MaintenanceTask $task) => $this->buildTaskRow($task))
            ->values()
            ->all();
    }

    public function getHasActiveTasksProperty(): bool
    {
        foreach (MaintenanceTask::cases() as $task) {
            if ($this->isActive(Cache::get($task->cacheKey(), []))) {
                return true;
            }
        }

        return false;
    }

    public function runTask(string $key): void
    {
        $task = MaintenanceTask::from($key);

        $cached = Cache::get($task->cacheKey(), []);

        if ($this->isActive($cached)) {
            Flux::toast(variant: 'info', text: __(':task is already running.', ['task' => $task->label()]));

            return;
        }

        Cache::put($task->cacheKey(), array_merge($cached, [
            'state' => 'queued',
            'queued_at' => now()->toIso8601String(),
        ]), now()->addDays(30));

        RunMaintenanceTask::dispatch($task);

        Flux::toast(variant: 'success', text: __(':task queued.', ['task' => $task->label()]));
    }

    /** @param array<string, mixed> $cached */
    private function isActive(array $cached): bool
    {
        $state = $cached['state'] ?? null;

        if (! in_array($state, ['queued', 'running'], true)) {
            return false;
        }

        return ! $this->isStale($cached);
    }

    /** @param array<string, mixed> $cached */
    private function isStale(array $cached): bool
    {
        $timestamp = $cached['started_at'] ?? $cached['queued_at'] ?? null;

        if ($timestamp === null) {
            return true;
        }

        return Carbon::parse($timestamp)->diffInHours() > 2;
    }

    /** @return array<string, mixed> */
    private function buildTaskRow(MaintenanceTask $task): array
    {
        $cached = Cache::get($task->cacheKey(), []);

        return [
            'key' => $task->value,
            'label' => $task->label(),
            'description' => $task->description(),
            'requiresPlex' => $task->requiresPlex(),
            'state' => $cached['state'] ?? null,
            'queued_at' => $cached['queued_at'] ?? null,
            'started_at' => $cached['started_at'] ?? null,
            'finished_at' => $cached['finished_at'] ?? null,
            'error' => $cached['error'] ?? null,
            'output' => $cached['output'] ?? null,
            'isActive' => $this->isActive($cached),
        ];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Maintenance')" :subheading="__('Run background tasks on demand')">
        @if ($this->hasActiveTasks)
            <div wire:poll.3s class="hidden" aria-hidden="true"></div>
        @endif

        <div class="divide-y divide-line">
            @foreach ($this->tasks as $task)
                <div wire:key="task-{{ $task['key'] }}" class="py-4 first:pt-0 last:pb-0">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <p class="text-sm font-medium text-ink leading-snug">{{ $task['label'] }}</p>
                            <p class="text-xs text-ink-muted leading-snug">{{ $task['description'] }}</p>
                        </div>

                        @php
                            $disabled = $task['isActive'] || ($task['requiresPlex'] && ! $plexConfigured);
                            $tooltip = match (true) {
                                $task['requiresPlex'] && ! $plexConfigured => __('Plex is not configured'),
                                default => null,
                            };
                        @endphp

                        <div class="shrink-0">
                            @if ($task['isActive'])
                                <span class="inline-flex h-6 items-center gap-1.5 whitespace-nowrap px-2 text-xs font-medium text-ink-muted" role="status">
                                    <flux:icon.loading class="size-3.5" />
                                    {{ $task['state'] === 'queued' ? __('Queued…') : __('Running…') }}
                                </span>
                            @else
                                <flux:button
                                    size="xs"
                                    variant="outline"
                                    wire:click="runTask('{{ $task['key'] }}')"
                                    :disabled="$disabled"
                                    :title="$tooltip"
                                >
                                    {{ __('Run now') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>

                    @if ($task['state'])
                        <div class="mt-2">
                            @if ($task['state'] === 'queued')
                                <p class="text-xs text-ink-muted">
                                    {{ __('Queued') }}
                                    @if ($task['queued_at'])
                                        · {{ \Illuminate\Support\Carbon::parse($task['queued_at'])->diffForHumans() }}
                                    @endif
                                </p>
                            @elseif ($task['state'] === 'running')
                                <p class="text-xs text-ink-muted">
                                    {{ __('Running') }}
                                    @if ($task['started_at'])
                                        · {{ \Illuminate\Support\Carbon::parse($task['started_at'])->diffForHumans() }}
                                    @endif
                                </p>
                            @elseif ($task['state'] === 'succeeded')
                                <p class="text-xs text-green-600 dark:text-green-400">
                                    {{ __('Succeeded') }}
                                    @if ($task['finished_at'])
                                        · {{ \Illuminate\Support\Carbon::parse($task['finished_at'])->diffForHumans() }}
                                    @endif
                                </p>
                            @elseif ($task['state'] === 'skipped')
                                <p class="text-xs text-ink-muted">
                                    {{ __('Skipped') }}
                                    @if ($task['finished_at'])
                                        · {{ \Illuminate\Support\Carbon::parse($task['finished_at'])->diffForHumans() }}
                                    @endif
                                    @if ($task['error'])
                                        · {{ $task['error'] }}
                                    @endif
                                </p>
                            @elseif ($task['state'] === 'failed')
                                <div class="space-y-1">
                                    <p class="text-xs text-red-600 dark:text-red-400">
                                        {{ __('Failed') }}
                                        @if ($task['finished_at'])
                                            · {{ \Illuminate\Support\Carbon::parse($task['finished_at'])->diffForHumans() }}
                                        @endif
                                        @if ($task['error'])
                                            · {{ $task['error'] }}
                                        @endif
                                    </p>
                                    @if ($task['output'])
                                        <details class="group">
                                            <summary class="cursor-pointer text-xs text-ink-muted hover:text-ink">
                                                {{ __('Show output') }}
                                            </summary>
                                            <pre class="mt-1 overflow-x-auto rounded bg-surface-alt p-2 text-xs font-mono text-ink-muted whitespace-pre-wrap break-words">{{ $task['output'] }}</pre>
                                        </details>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-pages::settings.layout>
</section>
