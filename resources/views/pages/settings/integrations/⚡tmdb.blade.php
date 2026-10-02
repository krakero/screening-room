<?php

use App\Concerns\TestsIntegrationConnection;
use App\Services\Tmdb\TmdbClient;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('TMDB settings')] class extends Component
{
    use TestsIntegrationConnection;

    /** @var array<string, string> */
    public array $regions = [
        'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia',
        'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands',
        'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland', 'IE' => 'Ireland',
        'NZ' => 'New Zealand', 'JP' => 'Japan', 'BR' => 'Brazil', 'MX' => 'Mexico', 'IN' => 'India', 'PL' => 'Poland',
    ];

    public string $token = '';

    public bool $tokenConfigured = false;

    public string $region = 'US';

    public bool $showWatchProviders = true;

    public bool $tokenFromEnv = false;

    /** @var array<int, array{label: string, ready: bool, needs?: string, note?: string}> */
    public array $features = [];

    public function mount(IntegrationSettings $settings): void
    {
        $this->tokenConfigured = $settings->configured('tmdb.token') || filled(config('services.tmdb.token'));
        $this->tokenFromEnv = ! $settings->configured('tmdb.token') && filled(config('services.tmdb.token'));
        $this->region = (string) $settings->get('tmdb.region', config('services.tmdb.region', 'US'));
        $this->showWatchProviders = app(TmdbClient::class)->showWatchProviders();
        $this->features = $this->buildFeatures();
    }

    /**
     * @return array<int, array{label: string, ready: bool, needs?: string, note?: string}>
     */
    private function buildFeatures(): array
    {
        return [
            [
                'label' => __('Title metadata, artwork, and search'),
                'ready' => $this->tokenConfigured,
                'needs' => __('API Read Access Token'),
            ],
            [
                'label' => __('Discover feed (trending, new releases, recommendations)'),
                'ready' => $this->tokenConfigured,
                'needs' => __('API Read Access Token'),
            ],
        ];
    }

    public function saveTmdb(IntegrationSettings $settings): void
    {
        $this->persistTmdb($settings);

        Flux::toast(variant: 'success', text: __('TMDB settings saved.'));
    }

    private function persistTmdb(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'token' => ['nullable', 'string'],
            'region' => ['required', 'string', 'size:2'],
            'showWatchProviders' => ['boolean'],
        ]);

        if (filled($validated['token'])) {
            $settings->set('tmdb.token', $validated['token']);
            $this->tokenConfigured = true;
            $this->tokenFromEnv = false;
        }

        $settings->set('tmdb.region', $validated['region']);
        $settings->set('tmdb.show_watch_providers', $validated['showWatchProviders']);

        $this->token = '';
        $this->features = $this->buildFeatures();
    }

    public function testTmdb(IntegrationSettings $settings): void
    {
        $this->persistTmdb($settings);

        $this->testConnection(app(TmdbClient::class), __('TMDB'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('TMDB settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('TMDB')" :subheading="__('Powers title metadata, artwork, and the Discover feed')">
        <x-settings.feature-list :items="$features" />

        <form wire:submit="saveTmdb" class="space-y-6">
            <x-settings.masked-input wire:model="token" :label="__('API Read Access Token')" :configured="$tokenConfigured" :description="__('From themoviedb.org/settings/api.') . ($tokenFromEnv ? ' ' . __('Currently using the TMDB_TOKEN environment variable — saving one here overrides it.') : '')" />

            <flux:select wire:model="region" :label="__('Region')" :description="__('Used for what\'s in theaters / coming soon on Discover.')">
                @foreach ($regions as $code => $label)
                    <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:switch wire:model="showWatchProviders" :label="__('Show where to watch')" :description="__('Streaming availability from JustWatch on title pages and a Service filter on lists.')" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <x-settings.test-connection-button :enabled="true" action="testTmdb" />
            </div>
        </form>
    </x-pages::settings.layout>
</section>
