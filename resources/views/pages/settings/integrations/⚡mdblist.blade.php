<?php

use App\Concerns\TestsIntegrationConnection;
use App\Services\MdbList\MdbListClient;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('MDBList settings')] class extends Component
{
    use TestsIntegrationConnection;

    public string $mdblistApiKey = '';

    public bool $mdblistApiKeyConfigured = false;

    /** @var array<int, array{label: string, ready: bool, needs?: string, note?: string}> */
    public array $features = [];

    public function mount(IntegrationSettings $settings): void
    {
        $this->mdblistApiKeyConfigured = $settings->configured('mdblist.api_key');
        $this->features = $this->buildFeatures($settings);
    }

    /**
     * @return array<int, array{label: string, ready: bool, needs?: string, note?: string}>
     */
    private function buildFeatures(IntegrationSettings $settings): array
    {
        $ready = $settings->configured('mdblist.api_key');

        return [
            [
                'label' => __('IMDb, Rotten Tomatoes, Metacritic, Letterboxd, and Trakt ratings on the title page'),
                'ready' => $ready,
                'needs' => __('API key'),
            ],
            [
                'label' => __('Kept fresh: new titles within an hour, everything else weekly'),
                'ready' => $ready,
                'needs' => __('API key'),
            ],
            [
                'label' => __('Test connection'),
                'ready' => $ready,
                'needs' => __('API key'),
            ],
        ];
    }

    public function saveMdbList(IntegrationSettings $settings): void
    {
        $this->persistMdbList($settings);

        Flux::toast(variant: 'success', text: __('MDBList settings saved.'));
    }

    private function persistMdbList(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'mdblistApiKey' => ['nullable', 'string'],
        ]);

        if (filled($validated['mdblistApiKey'])) {
            $settings->set('mdblist.api_key', $validated['mdblistApiKey']);
            $this->mdblistApiKeyConfigured = true;
        }

        $this->mdblistApiKey = '';
        $this->features = $this->buildFeatures($settings);
    }

    public function testMdbList(IntegrationSettings $settings): void
    {
        $this->persistMdbList($settings);

        $this->testConnection(app(MdbListClient::class), __('MDBList'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('MDBList settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('MDBList')" :subheading="__('IMDb, Rotten Tomatoes, Metacritic, Letterboxd, and Trakt via MDBList')">
        <x-settings.feature-list :items="$features" />

        <form wire:submit="saveMdbList" class="space-y-6">
            <x-settings.masked-input wire:model="mdblistApiKey" :label="__('API key')" :configured="$mdblistApiKeyConfigured" :description="__('From your MDBList account preferences at mdblist.com/preferences.')" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <x-settings.test-connection-button :enabled="true" action="testMdbList" />
            </div>
        </form>
    </x-pages::settings.layout>
</section>
