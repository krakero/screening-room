<?php

use App\Enums\AppLayout;
use App\Services\Stats\StatsCacheVersion;
use App\Support\DisplayTimezone;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component
{
    public string $timezone = DisplayTimezone::DEFAULT;

    public string $layout = AppLayout::Sidebar->value;

    public function mount(): void
    {
        $this->timezone = auth()->user()->timezone ?? config('app.display_timezone', DisplayTimezone::DEFAULT);
        $this->layout = auth()->user()->layout?->value ?? AppLayout::Sidebar->value;
    }

    #[Computed]
    public function timezoneOptions(): array
    {
        return DisplayTimezone::options();
    }

    public function saveTimezone(StatsCacheVersion $version): void
    {
        $this->validate(['timezone' => ['required', 'string', 'timezone:all']]);

        auth()->user()->update(['timezone' => $this->timezone]);

        $version->bump();

        Flux::toast(variant: 'success', text: __('Timezone saved.'));
    }

    public function saveLayout(): void
    {
        $this->validate(['layout' => ['required', Rule::enum(AppLayout::class)]]);

        auth()->user()->update(['layout' => $this->layout]);

        $this->redirect(route('appearance.edit'), navigate: false);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
            <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
        </flux:radio.group>

        <flux:separator class="my-6" />

        <flux:heading level="3">{{ __('Accent color') }}</flux:heading>
        <flux:subheading class="mb-4">{{ __('Pick a swatch or enter a custom hex color.') }}</flux:subheading>

        <livewire:accent-picker />

        <flux:separator class="my-6" />

        <flux:heading level="3">{{ __('Layout') }}</flux:heading>
        <flux:subheading class="mb-4">{{ __('Choose top navigation or a sidebar on larger screens. Phones always use the bottom tab bar.') }}</flux:subheading>

        <form wire:submit="saveLayout" class="max-w-sm space-y-4">
            <flux:radio.group wire:model="layout" variant="segmented">
                @foreach (AppLayout::cases() as $option)
                    <flux:radio value="{{ $option->value }}" icon="{{ $option === AppLayout::Header ? 'bars-3' : 'view-columns' }}">{{ $option->label() }}</flux:radio>
                @endforeach
            </flux:radio.group>

            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </form>

        <flux:separator class="my-6" />

        <flux:heading level="3">{{ __('Regional') }}</flux:heading>
        <flux:subheading class="mb-4">{{ __('Used to decide what day and time watched dates, stats, and Airing This Week show.') }}</flux:subheading>

        <form wire:submit="saveTimezone" class="max-w-sm space-y-4">
            <flux:select wire:model="timezone" :label="__('Timezone')">
                @foreach ($this->timezoneOptions as $region => $zones)
                    <flux:select.group :heading="$region">
                        @foreach ($zones as $identifier => $label)
                            <flux:select.option value="{{ $identifier }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select.group>
                @endforeach
            </flux:select>

            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
