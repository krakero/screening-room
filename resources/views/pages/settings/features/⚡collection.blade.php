<?php

use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Collection')] class extends Component
{
    public bool $collectionEnabled = false;

    public function mount(): void
    {
        $this->collectionEnabled = auth()->user()->collection_enabled ?? true;
    }

    public function updatedCollectionEnabled(): void
    {
        auth()->user()->update(['collection_enabled' => $this->collectionEnabled]);

        Flux::toast(
            variant: 'success',
            text: $this->collectionEnabled
                ? __('Collection enabled.')
                : __('Collection disabled.')
        );
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Collection') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Collection')" :subheading="__('Track your physical and digital media collection')">
        <flux:switch wire:model.live="collectionEnabled" :label="__('Enable collection')" :description="__('When enabled, you can track your physical media (4K UHD, Blu-ray, DVD) and digital purchases alongside your Plex library.')" />

        @if ($this->collectionEnabled)
            <flux:card variant="outline" :highlight="false" class="mt-6 border-line bg-surface">
                <flux:card.header>
                    <flux:card.heading>{{ __('Import') }}</flux:card.heading>
                    <flux:card.subheading>{{ __('Bulk import collection items from CSV') }}</flux:card.subheading>
                </flux:card.header>

                <flux:card.body>
                    @if (class_exists(\App\Livewire\Pages\Settings\Features\CollectionImport::class))
                        @livewire('pages::settings.features.collection-import', key('collection-import'))
                    @else
                        <flux:text variant="subtle">{{ __('CSV import will be available soon.') }}</flux:text>
                    @endif
                </flux:card.body>
            </flux:card>
        @endif
    </x-pages::settings.layout>
</section>
