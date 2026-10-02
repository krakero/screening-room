<?php

use App\Jobs\ImportCollectionCsv;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Collection import')] class extends Component
{
    use WithFileUploads;

    public ?UploadedFile $csvFile = null;

    /**
     * @return array{status: string, finished_at?: string, started_at?: string, current?: int, total?: int, summary?: array<string, mixed>, error?: string, parse_errors?: array<int, array{row: int, message: string}>, unmatched?: array<int, array{row: int, title: string, year: ?int, type: string, season?: int, reason: string}>}|null
     */
    public function getLastImportProperty(): ?array
    {
        return app(IntegrationSettings::class)->get('collection.last_import');
    }

    /**
     * @return array<int, array{row: int, message: string}>
     */
    public function getParseErrorsProperty(): array
    {
        return $this->lastImport['parse_errors'] ?? [];
    }

    /**
     * @return array<int, array{row: int, title: string, year: ?int, type: string, season?: int, reason: string}>
     */
    public function getUnmatchedProperty(): array
    {
        return $this->lastImport['unmatched'] ?? [];
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

        $current = $this->lastImport['current'] ?? 0;
        $total = $this->lastImport['total'] ?? 0;

        return __('Importing :current/:total rows', ['current' => $current, 'total' => $total]);
    }

    public function getProgressPercentProperty(): int
    {
        $total = $this->lastImport['total'] ?? 0;
        $current = $this->lastImport['current'] ?? 0;

        return $total > 0 ? (int) round(min($current, $total) / $total * 100) : 0;
    }

    public function importCsv(): void
    {
        $this->validate([
            'csvFile' => ['required', 'file', 'extensions:csv,txt', 'max:10240'],
        ]);

        $path = $this->csvFile->store('imports', 'local');

        $this->reset('csvFile');

        ImportCollectionCsv::dispatch($path);

        Flux::toast(variant: 'success', text: __('CSV uploaded. Import started.'));
    }
}; ?>

<section class="w-full">
    <flux:heading level="2">{{ __('Collection import') }}</flux:heading>
    <flux:text variant="subtle" class="mt-1">{{ __('Import collection items from a CSV file') }}</flux:text>

    @if ($this->isRunning)
        <flux:card variant="outline" :highlight="false" class="mt-6 border-line bg-surface" wire:poll.2s>
            <div class="flex items-center justify-between gap-4">
                <flux:text>{{ $this->progressLabel }}</flux:text>
            </div>
            <x-media.progress :value="$this->progressPercent" class="mt-2" />
        </flux:card>
    @elseif ($this->lastImport)
        <flux:card variant="outline" :highlight="false" class="mt-6 border-line bg-surface">
            @if ($this->lastImport['status'] === 'success')
                <flux:text>{{ __('Last import: succeeded at :time', ['time' => $this->lastImport['finished_at']]) }}</flux:text>
                <ul class="mt-2 space-y-1 text-sm text-ink-muted">
                    @foreach ($this->lastImport['summary'] ?? [] as $label => $value)
                        <li>{{ $label }}: {{ $value }}</li>
                    @endforeach
                </ul>
            @else
                <flux:text color="red">{{ __('Last import: failed at :time', ['time' => $this->lastImport['finished_at']]) }}</flux:text>
                <flux:text size="sm" variant="subtle" class="mt-1">{{ $this->lastImport['error'] ?? __('Unknown error.') }}</flux:text>
            @endif
        </flux:card>
    @endif

    @if (! $this->isRunning && $this->parseErrors !== [])
        <flux:card variant="outline" :highlight="false" class="mt-4 border-line bg-surface">
            <flux:text>{{ trans_choice(':count parse error|:count parse errors', count($this->parseErrors), ['count' => count($this->parseErrors)]) }}</flux:text>
            <ul class="mt-2 space-y-1 text-sm text-ink-muted">
                @foreach ($this->parseErrors as $error)
                    <li>Row {{ $error['row'] }}: {{ $error['message'] }}</li>
                @endforeach
            </ul>
        </flux:card>
    @endif

    @if (! $this->isRunning && $this->unmatched !== [])
        <flux:card variant="outline" :highlight="false" class="mt-4 border-line bg-surface">
            <flux:text>{{ trans_choice(':count unmatched row|:count unmatched rows', count($this->unmatched), ['count' => count($this->unmatched)]) }}</flux:text>
            <ul class="mt-2 space-y-1 text-sm text-ink-muted">
                @foreach ($this->unmatched as $entry)
                    <li>
                        Row {{ $entry['row'] }}: {{ $entry['title'] }}
                        @if ($entry['year'])
                            ({{ $entry['year'] }})
                        @endif
                        @if (isset($entry['season']))
                            Season {{ $entry['season'] }}
                        @endif
                        — {{ $entry['reason'] }}
                    </li>
                @endforeach
            </ul>
        </flux:card>
    @endif

    <form wire:submit="importCsv" class="mt-6 space-y-6">
        <flux:input wire:model="csvFile" type="file" :label="__('CSV file')" accept=".csv,.txt" :description="__('Upload a CSV file with your collection items. See docs/collection-import-sample.csv for the format.')" />

        <flux:button variant="primary" type="submit">{{ __('Upload and import') }}</flux:button>
    </form>
</section>
