<?php

use App\Support\IntegrationSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::setup')] #[Title('All set — Screening Room')] class extends Component
{
    public function mount(IntegrationSettings $settings): void
    {
        $settings->set('setup.finished', true);
    }
}; ?>

<x-setup.layout step="done" :title="__('You\'re all set')" :subtitle="__('Screening Room is ready. You can revisit any of this any time from Settings.')">
    <flux:button variant="primary" class="w-full" :href="route('dashboard')" wire:navigate>{{ __('Go to Up Next') }}</flux:button>
</x-setup.layout>
