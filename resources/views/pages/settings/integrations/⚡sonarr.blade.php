<?php

use App\Concerns\ManagesWebhookSecret;
use App\Concerns\TestsIntegrationConnection;
use App\Services\Sonarr\SonarrClient;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sonarr settings')] class extends Component
{
    use ManagesWebhookSecret, TestsIntegrationConnection;

    public string $sonarrUrl = '';

    public string $sonarrApiKey = '';

    public bool $sonarrApiKeyConfigured = false;

    public string $arrWebhookUrl = '';

    /** @var array<int, array{label: string, ready: bool, needs?: string, note?: string}> */
    public array $features = [];

    public function mount(IntegrationSettings $settings): void
    {
        $this->sonarrUrl = (string) $settings->get('sonarr.url', '');
        $this->sonarrApiKeyConfigured = $settings->configured('sonarr.api_key');
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
                'note' => __('Works from the webhook URL alone — paste it into Sonarr, no other setting needed.'),
            ],
            [
                'label' => __('Reconcile every 6 hours (catches events a webhook missed)'),
                'ready' => $settings->configured('sonarr.url', 'sonarr.api_key'),
                'needs' => __('Server URL, API key'),
            ],
            [
                'label' => __('Test connection'),
                'ready' => $settings->configured('sonarr.url', 'sonarr.api_key'),
                'needs' => __('Server URL, API key'),
            ],
        ];
    }

    public function saveSonarr(IntegrationSettings $settings): void
    {
        $this->persistSonarr($settings);

        Flux::toast(variant: 'success', text: __('Sonarr settings saved.'));
    }

    private function persistSonarr(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'sonarrUrl' => ['nullable', 'url'],
            'sonarrApiKey' => ['nullable', 'string'],
        ]);

        $settings->set('sonarr.url', $validated['sonarrUrl'] ?: null);

        if (filled($validated['sonarrApiKey'])) {
            $settings->set('sonarr.api_key', $validated['sonarrApiKey']);
            $this->sonarrApiKeyConfigured = true;
        }

        $this->sonarrApiKey = '';
        $this->features = $this->buildFeatures($settings);
    }

    public function testSonarr(IntegrationSettings $settings): void
    {
        if (! class_exists(SonarrClient::class)) {
            return;
        }

        $this->persistSonarr($settings);

        $this->testConnection(app(SonarrClient::class), __('Sonarr'));
    }

    public function regenerateArrWebhookSecret(IntegrationSettings $settings): void
    {
        $this->arrWebhookUrl = $this->regenerateWebhookUrl($settings, 'webhooks.arr_secret', 'arr');

        Flux::toast(variant: 'success', text: __('Webhook URL regenerated.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Sonarr settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Sonarr')" :subheading="__('Show download and availability status')">
        <x-settings.feature-list :items="$features" />

        <form wire:submit="saveSonarr" class="space-y-6">
            <flux:input wire:model="sonarrUrl" :label="__('Server URL')" placeholder="http://sonarr.local:8989" :description="__('Your Sonarr address, e.g. http://192.168.1.10:8989.')" />
            <x-settings.masked-input wire:model="sonarrApiKey" :label="__('API key')" :configured="$sonarrApiKeyConfigured" :description="__('Sonarr → Settings → General → Security → API Key.')" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <x-settings.test-connection-button :enabled="class_exists(SonarrClient::class)" action="testSonarr" />
            </div>
        </form>

        <x-settings.webhook-url :url="$arrWebhookUrl" regenerate="regenerateArrWebhookSecret">
            <flux:text size="sm" variant="subtle">{{ __('Used by Sonarr, Radarr, and Seerr.') }}</flux:text>
        </x-settings.webhook-url>
    </x-pages::settings.layout>
</section>
