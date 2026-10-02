<?php

use App\Support\DisplayTimezone;
use App\Support\IntegrationSettings;
use App\Support\SetupProgress;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::setup')] #[Title('Preferences — Setup')] class extends Component
{
    /** @var array<string, string> */
    public array $regions = [
        'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia',
        'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands',
        'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland', 'IE' => 'Ireland',
        'NZ' => 'New Zealand', 'JP' => 'Japan', 'BR' => 'Brazil', 'MX' => 'Mexico', 'IN' => 'India', 'PL' => 'Poland',
    ];

    public string $region = 'US';

    public string $timezone = DisplayTimezone::DEFAULT;

    public function mount(IntegrationSettings $settings, SetupProgress $progress): void
    {
        $this->region = (string) $settings->get('tmdb.region', config('services.tmdb.region', 'US'));
        $this->timezone = auth()->user()->timezone ?? config('app.display_timezone', DisplayTimezone::DEFAULT);

        $progress->markReached('preferences');
    }

    #[Computed]
    public function timezoneOptions(): array
    {
        return DisplayTimezone::options();
    }

    public function savePreferences(IntegrationSettings $settings): void
    {
        $this->validate([
            'region' => ['required', 'string', 'size:2'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ]);

        $settings->set('tmdb.region', $this->region);

        auth()->user()->update(['timezone' => $this->timezone]);

        $this->redirectRoute('setup.import', navigate: true);
    }
}; ?>

<x-setup.layout step="preferences" :title="__('Make it yours')" :subtitle="__('All of this can be changed later in Settings.')" back-route="setup.tmdb" skip-route="setup.import">
    <div class="space-y-8">
        <div>
            <flux:heading size="sm">{{ __('Theme') }}</flux:heading>
            <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" class="mt-2">
                <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
                <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
                <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
            </flux:radio.group>
        </div>

        <div>
            <flux:heading size="sm">{{ __('Accent color') }}</flux:heading>
            <div class="mt-2">
                <livewire:accent-picker />
            </div>
        </div>

        <form wire:submit="savePreferences" class="space-y-4">
            <flux:select wire:model="region" :label="__('Region')" :description="__('Used for what\'s in theaters / coming soon on Discover.')">
                @foreach ($regions as $code => $label)
                    <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="timezone" :label="__('Timezone')" :description="__('Used to decide what day and time watched dates and stats show.')">
                @foreach ($this->timezoneOptions as $region => $zones)
                    <flux:select.group :heading="$region">
                        @foreach ($zones as $identifier => $label)
                            <flux:select.option value="{{ $identifier }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select.group>
                @endforeach
            </flux:select>

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Continue') }}</flux:button>
        </form>
    </div>
</x-setup.layout>
