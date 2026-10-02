<?php

use App\Jobs\ImportTraktExport;
use App\Support\SetupProgress;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::setup')] #[Title('Import your history — Setup')] class extends Component
{
    use WithFileUploads;

    public ?UploadedFile $traktFile = null;

    public bool $uploaded = false;

    public function mount(SetupProgress $progress): void
    {
        $progress->markReached('import');
    }

    public function importTrakt(): void
    {
        $this->validate([
            'traktFile' => ['required', 'file', 'extensions:zip', 'max:51200'],
        ]);

        $path = $this->traktFile->store('imports', 'local');

        $this->reset('traktFile');
        $this->uploaded = true;

        if (class_exists(ImportTraktExport::class)) {
            ImportTraktExport::dispatch($path);
        }

        Flux::toast(variant: 'success', text: __('Trakt export uploaded. Import started in the background.'));
    }

    public function continueSetup(): void
    {
        $this->redirectRoute('setup.plex', navigate: true);
    }
}; ?>

<x-setup.layout step="import" :title="__('Import your history')" :subtitle="__('Optional — bring in watch history, ratings, watchlist, and custom lists from Trakt.')" back-route="setup.preferences" skip-route="setup.plex">
    @if ($uploaded)
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            <flux:callout.text>{{ __('Your export is uploaded and importing in the background. You can keep going — check progress any time in Settings → Trakt import.') }}</flux:callout.text>
        </flux:callout>

        <flux:button variant="primary" class="w-full" wire:click="continueSetup">{{ __('Continue') }}</flux:button>
    @else
        <form wire:submit="importTrakt" class="space-y-6">
            <flux:input wire:model="traktFile" type="file" :label="__('Trakt export (.zip)')" accept=".zip" :description="__('From trakt.tv: Settings → Data → Export.')" />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Upload and continue') }}</flux:button>
        </form>

        <flux:text size="sm" variant="subtle" class="mt-4">{{ __('Plex history backfills automatically once you connect Plex in the next step.') }}</flux:text>
    @endif
</x-setup.layout>
