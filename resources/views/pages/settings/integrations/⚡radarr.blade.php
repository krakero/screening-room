<?php

use App\Concerns\ManagesWebhookSecret;
use App\Concerns\TestsIntegrationConnection;
use App\Services\Radarr\RadarrClient;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Radarr settings')] class extends Component
{
    use ManagesWebhookSecret, TestsIntegrationConnection;

    public string $radarrUrl = '';

    public string $radarrApiKey = '';

    public bool $radarrApiKeyConfigured = false;

    public string $arrWebhookUrl = '';

    /** @var array<int, array{label: string, ready: bool, needs?: string, note?: string}> */
    public array $features = [];

    public function mount(IntegrationSettings $settings): void
    {
        $this->radarrUrl = (string) $settings->get('radarr.url', '');
        $this->radarrApiKeyConfigured = $settings->configured('radarr.api_key');
        $this->arrWebhookUrl = $this->ensureWebhookUrl($settings, 'webhooks.arr_secret', 'arr');
        $this->features = $this->buildFeatures($settings);
    }

    /**
     * @return array<int, array{label: string, ready: bool, needs?: string, note?: string}>
     */
    private function buildFeatures(IntegrationSettings $settings): array
    {
        return [
            [
                'label' => __('Library status updates (downloading → available)'),
                'ready' => true,
                'note' => __('Works from the webhook URL alone — paste it into Radarr, no other setting needed.'),
            ],
            [
                'label' => __('Reconcile every 6 hours (catches events a webhook missed)'),
                'ready' => $settings->configured('radarr.url', 'radarr.api_key'),
                'needs' => __('Server URL, API key'),
            ],
            [
                'label' => __('Test connection'),
                'ready' => $settings->configured('radarr.url', 'radarr.api_key'),
                'needs' => __('Server URL, API key'),
            ],
        ];
    }

    public function saveRadarr(IntegrationSettings $settings): void
    {
        $this->persistRadarr($settings);

        Flux::toast(variant: 'success', text: __('Radarr settings saved.'));
    }

    private function persistRadarr(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'radarrUrl' => ['nullable', 'url'],
            'radarrApiKey' => ['nullable', 'string'],
        ]);

        $settings->set('radarr.url', $validated['radarrUrl'] ?: null);

        if (filled($validated['radarrApiKey'])) {
            $settings->set('radarr.api_key', $validated['radarrApiKey']);
            $this->radarrApiKeyConfigured = true;
        }

        $this->radarrApiKey = '';
        $this->features = $this->buildFeatures($settings);
    }

    public function testRadarr(IntegrationSettings $settings): void
    {
        if (! class_exists(RadarrClient::class)) {
            return;
        }

        $this->persistRadarr($settings);

        $this->testConnection(app(RadarrClient::class), __('Radarr'));
    }

    public function regenerateArrWebhookSecret(IntegrationSettings $settings): void
    {
        $this->arrWebhookUrl = $this->regenerateWebhookUrl($settings, 'webhooks.arr_secret', 'arr');

        Flux::toast(variant: 'success', text: __('Webhook URL regenerated.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Radarr settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Radarr')" :subheading="__('Movie download and availability status')">
        <x-settings.feature-list :items="$features" />

        <form wire:submit="saveRadarr" class="space-y-6">
            <flux:input wire:model="radarrUrl" :label="__('Server URL')" placeholder="http://radarr.local:7878" :description="__('Your Radarr address, e.g. http://192.168.1.10:7878.')" />
            <x-settings.masked-input wire:model="radarrApiKey" :label="__('API key')" :configured="$radarrApiKeyConfigured" :description="__('Radarr → Settings → General → Security → API Key.')" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <x-settings.test-connection-button :enabled="class_exists(RadarrClient::class)" action="testRadarr" />
            </div>
        </form>

        <x-settings.webhook-url :url="$arrWebhookUrl" regenerate="regenerateArrWebhookSecret">
            <flux:text size="sm" variant="subtle">{{ __('Used by Sonarr, Radarr, and Seerr.') }}</flux:text>
        </x-settings.webhook-url>
    </x-pages::settings.layout>
</section>
