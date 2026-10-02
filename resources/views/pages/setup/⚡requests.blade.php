<?php

use App\Support\IntegrationSettings;
use App\Support\SetupProgress;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::setup')] #[Title('Requests & downloads — Setup')] class extends Component
{
    /** @var array<int, array{label: string, route: string, configured: bool}> */
    public array $services = [];

    public function mount(IntegrationSettings $settings, SetupProgress $progress): void
    {
        $progress->markReached('requests');

        $this->services = [
            ['label' => __('Seerr'), 'route' => 'settings.integrations.seerr', 'configured' => $settings->configured('seerr.url', 'seerr.api_key')],
            ['label' => __('Sonarr'), 'route' => 'settings.integrations.sonarr', 'configured' => $settings->configured('sonarr.url', 'sonarr.api_key')],
            ['label' => __('Radarr'), 'route' => 'settings.integrations.radarr', 'configured' => $settings->configured('radarr.url', 'radarr.api_key')],
        ];
    }

    public function continueSetup(): void
    {
        $this->redirectRoute('setup.notifications', navigate: true);
    }
}; ?>

<x-setup.layout step="requests" :title="__('Requests & downloads')" :subtitle="__('Optional — request titles and track Sonarr/Radarr download status.')" back-route="setup.plex" skip-route="setup.notifications">
    <ul class="space-y-3">
        @foreach ($services as $service)
            <li class="flex items-center justify-between rounded-lg border border-line bg-surface-raised p-4">
                <div class="flex items-center gap-2">
                    <span class="text-ink">{{ $service['label'] }}</span>
                    <x-settings.status-dot :configured="$service['configured']" />
                </div>
                <flux:button size="sm" variant="outline" :href="route($service['route'])" wire:navigate>
                    {{ $service['configured'] ? __('Edit') : __('Configure') }}
                </flux:button>
            </li>
        @endforeach
    </ul>

    <flux:text size="sm" variant="subtle" class="mt-4">{{ __('These open the real Settings pages so you\'re not filling anything out twice — come back here (or just keep going from Settings) when you\'re done.') }}</flux:text>

    <flux:button variant="primary" class="mt-8 w-full" wire:click="continueSetup">{{ __('Continue') }}</flux:button>
</x-setup.layout>
