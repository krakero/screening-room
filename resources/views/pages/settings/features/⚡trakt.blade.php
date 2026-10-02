<?php

use App\Jobs\ImportTraktExport;
use App\Jobs\RetryFailedTraktTitles;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Trakt import')] class extends Component
{
    use WithFileUploads;

    public ?UploadedFile $traktFile = null;

    /**
     * @return array{status: string, finished_at?: string, started_at?: string, batch_id?: string, phase?: string, current?: int, total?: int, summary?: array<string, mixed>, error?: string, export_path?: string, dry_run?: bool, failed_titles?: array<string, array{type: string, tmdb_id: int, error: string}>}|null
     */
    public function getLastImportProperty(): ?array
    {
        return app(IntegrationSettings::class)->get('trakt.last_import');
    }

    /**
     * @return array<int, array{type: string, tmdb_id: int, error: string}>
     */
    public function getFailedTitlesProperty(): array
    {
        return array_values($this->lastImport['failed_titles'] ?? []);
    }

    public function getIsRunningProperty(): bool
    {
        return ($this->lastImport['status'] ?? null) === 'running';
    }

    public function getProgressLabelProperty(): ?string
    {
        if (! $this->isRunning) {
            return null;
        }

        $phase = $this->lastImport['phase'] ?? null;
        $current = $this->lastImport['current'] ?? 0;
        $total = $this->lastImport['total'] ?? 0;

        return match ($phase) {
            'titles' => __('Importing titles :current/:total', ['current' => $current, 'total' => $total]),
            'plays' => __('Importing plays :current/:total', ['current' => $current, 'total' => $total]),
            'ratings' => __('Importing ratings…'),
            'watchlist' => __('Importing watchlist…'),
            'lists' => __('Importing custom lists…'),
            default => __('Starting import…'),
        };
    }

    public function getProgressPercentProperty(): int
    {
        $total = $this->lastImport['total'] ?? 0;
        $current = $this->lastImport['current'] ?? 0;

        return $total > 0 ? (int) round(min($current, $total) / $total * 100) : 0;
    }

    public function importTrakt(): void
    {
        $this->validate([
            'traktFile' => ['required', 'file', 'extensions:zip', 'max:524288'],
        ]);

        $path = $this->traktFile->store('imports', 'local');

        $this->reset('traktFile');

        if (! class_exists(ImportTraktExport::class)) {
            Flux::toast(variant: 'success', text: __('Export uploaded. Import will run once Trakt import is available.'));

            return;
        }

        ImportTraktExport::dispatch($path);

        Flux::toast(variant: 'success', text: __('Trakt export uploaded. Import started.'));
    }

    public function cancelImport(): void
    {
        $batchId = $this->lastImport['batch_id'] ?? null;

        if ($batchId !== null && ($batch = Bus::findBatch($batchId)) !== null) {
            $batch->cancel();
        }

        Flux::toast(variant: 'success', text: __('Import cancelled.'));
    }

    public function retryFailedTitles(): void
    {
        $failedTitles = $this->failedTitles;
        $exportPath = $this->lastImport['export_path'] ?? null;

        if ($failedTitles === [] || $exportPath === null || ! Storage::disk('local')->exists($exportPath)) {
            Flux::toast(variant: 'danger', text: __('Failed titles can\'t be retried: the original export is no longer available.'));

            return;
        }

        $references = array_map(
            fn (array $entry): array => ['type' => $entry['type'], 'tmdb_id' => $entry['tmdb_id']],
            $failedTitles,
        );

        RetryFailedTraktTitles::dispatch($exportPath, $references, $this->lastImport['dry_run'] ?? false);

        Flux::toast(variant: 'success', text: __('Retrying failed titles.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Trakt import') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Trakt import')" :subheading="__('One-time import of history, ratings, watchlist, and custom lists')">
        <x-settings.feature-list :items="[
            ['label' => __('Watch history → plays'), 'ready' => true, 'note' => __('Only entries with a matching TMDB title are imported.')],
            ['label' => __('Ratings (movies, shows, episodes)'), 'ready' => true],
            ['label' => __('Watchlist and custom lists'), 'ready' => true],
        ]" />

        @if ($this->isRunning)
            <flux:card variant="outline" :highlight="false" class="border-line bg-surface" wire:poll.2s>
                <div class="flex items-center justify-between gap-4">
                    <flux:text>{{ $this->progressLabel }}</flux:text>
                    <flux:button size="sm" variant="ghost" wire:click="cancelImport" wire:confirm="{{ __('Cancel the running import?') }}">{{ __('Cancel') }}</flux:button>
                </div>
                <x-media.progress :value="$this->progressPercent" class="mt-2" />
            </flux:card>
        @elseif ($this->lastImport)
            <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                @if ($this->lastImport['status'] === 'success')
                    <flux:text>{{ __('Last import: succeeded at :time', ['time' => $this->lastImport['finished_at']]) }}</flux:text>
                    <ul class="mt-2 space-y-1 text-sm text-ink-muted">
                        @foreach ($this->lastImport['summary'] ?? [] as $label => $value)
                            <li>{{ $label }}: {{ $value }}</li>
                        @endforeach
                    </ul>
                @elseif ($this->lastImport['status'] === 'completed_with_errors')
                    <flux:text color="amber">{{ __('Last import: finished with some errors at :time', ['time' => $this->lastImport['finished_at']]) }}</flux:text>
                    <flux:text size="sm" variant="subtle" class="mt-1">{{ __('Some titles could not be imported. Check the queue\'s failed jobs for details.') }}</flux:text>
                    <ul class="mt-2 space-y-1 text-sm text-ink-muted">
                        @foreach ($this->lastImport['summary'] ?? [] as $label => $value)
                            <li>{{ $label }}: {{ $value }}</li>
                        @endforeach
                    </ul>
                @elseif ($this->lastImport['status'] === 'failed_incomplete')
                    <flux:text color="red">{{ __('Last import: stopped before finishing at :time', ['time' => $this->lastImport['finished_at']]) }}</flux:text>
                    <flux:text size="sm" variant="subtle" class="mt-1">{{ __('Titles were imported, but plays, ratings, watchlist, or lists were not. Re-upload the export to finish the import.') }}</flux:text>
                @elseif ($this->lastImport['status'] === 'cancelled')
                    <flux:text color="amber">{{ __('Last import: cancelled at :time', ['time' => $this->lastImport['finished_at']]) }}</flux:text>
                @else
                    <flux:text color="red">{{ __('Last import: failed at :time', ['time' => $this->lastImport['finished_at']]) }}</flux:text>
                    <flux:text size="sm" variant="subtle" class="mt-1">{{ $this->lastImport['error'] ?? __('Unknown error.') }}</flux:text>
                @endif
            </flux:card>
        @endif

        @if (! $this->isRunning && $this->failedTitles !== [])
            <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                <div class="flex items-center justify-between gap-4">
                    <flux:text>{{ trans_choice(':count title failed to import|:count titles failed to import', count($this->failedTitles), ['count' => count($this->failedTitles)]) }}</flux:text>
                    <flux:button size="sm" variant="ghost" wire:click="retryFailedTitles">{{ __('Retry failed') }}</flux:button>
                </div>
                <ul class="mt-2 space-y-1 text-sm text-ink-muted">
                    @foreach ($this->failedTitles as $entry)
                        <li>{{ $entry['type'] }} tmdb:{{ $entry['tmdb_id'] }} — {{ $entry['error'] }}</li>
                    @endforeach
                </ul>
            </flux:card>
        @endif

        @if (class_exists(ImportTraktExport::class))
            <form wire:submit="importTrakt" class="space-y-6">
                <flux:input wire:model="traktFile" type="file" :label="__('Export file')" accept=".zip" :description="__('The .zip Trakt emails you from Settings → Data → Export on trakt.tv.')" />

                <flux:button variant="primary" type="submit">{{ __('Upload and import') }}</flux:button>
            </form>
        @else
            <div class="space-y-6">
                <flux:input wire:model="traktFile" type="file" :label="__('Export file')" accept=".zip" :description="__('The .zip Trakt emails you from Settings → Data → Export on trakt.tv.')" />

                <div>
                    <flux:button variant="primary" wire:click="importTrakt">{{ __('Upload and import') }}</flux:button>
                    <flux:text size="sm" variant="subtle" class="mt-2">{{ __('Import will run automatically once Trakt import support lands.') }}</flux:text>
                </div>
            </div>
        @endif
    </x-pages::settings.layout>
</section>
