<?php

use App\Concerns\ManagesWebhookSecret;
use App\Concerns\TestsIntegrationConnection;
use App\Services\Seerr\SeerrClient;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Seerr settings')] class extends Component
{
    use ManagesWebhookSecret, TestsIntegrationConnection;

    public string $seerrUrl = '';

    public string $seerrApiKey = '';

    public bool $seerrApiKeyConfigured = false;

    public string $arrWebhookUrl = '';

    /** @var array<int, array{label: string, ready: bool, needs?: string, note?: string}> */
    public array $features = [];

    public function mount(IntegrationSettings $settings): void
    {
        $this->seerrUrl = (string) $settings->get('seerr.url', '');
        $this->seerrApiKeyConfigured = $settings->configured('seerr.api_key');
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
                'label' => __('Request movies and shows from title pages'),
                'ready' => $settings->configured('seerr.url', 'seerr.api_key'),
                'needs' => __('Server URL, API key'),
            ],
            [
                'label' => __('Library status updates (pending approval → approved → available)'),
                'ready' => true,
                'note' => __('Works from the webhook URL alone — paste it into Seerr, no other setting needed.'),
            ],
            [
                'label' => __('Test connection'),
                'ready' => $settings->configured('seerr.url', 'seerr.api_key'),
                'needs' => __('Server URL, API key'),
            ],
        ];
    }

    public function saveSeerr(IntegrationSettings $settings): void
    {
        $this->persistSeerr($settings);

        Flux::toast(variant: 'success', text: __('Seerr settings saved.'));
    }

    private function persistSeerr(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'seerrUrl' => ['nullable', 'url'],
            'seerrApiKey' => ['nullable', 'string'],
        ]);

        $settings->set('seerr.url', $validated['seerrUrl'] ?: null);

        if (filled($validated['seerrApiKey'])) {
            $settings->set('seerr.api_key', $validated['seerrApiKey']);
            $this->seerrApiKeyConfigured = true;
        }

        $this->seerrApiKey = '';
        $this->features = $this->buildFeatures($settings);
    }

    public function testSeerr(IntegrationSettings $settings): void
    {
        if (! class_exists(SeerrClient::class)) {
            return;
        }

        $this->persistSeerr($settings);

        $this->testConnection(app(SeerrClient::class), __('Seerr'));
    }

    public function regenerateArrWebhookSecret(IntegrationSettings $settings): void
    {
        $this->arrWebhookUrl = $this->regenerateWebhookUrl($settings, 'webhooks.arr_secret', 'arr');

        Flux::toast(variant: 'success', text: __('Webhook URL regenerated.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Seerr settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Seerr')" :subheading="__('Request titles and track their library status')">
        <x-settings.feature-list :items="$features" />

        <form wire:submit="saveSeerr" class="space-y-6">
            <flux:input wire:model="seerrUrl" :label="__('Server URL')" placeholder="http://seerr.local:5055" :description="__('Your Seerr address, e.g. http://192.168.1.10:5055.')" />
            <x-settings.masked-input wire:model="seerrApiKey" :label="__('API key')" :configured="$seerrApiKeyConfigured" :description="__('Seerr → Settings → General → API Key.')" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <x-settings.test-connection-button :enabled="class_exists(SeerrClient::class)" action="testSeerr" />
            </div>
        </form>

        <x-settings.webhook-url :url="$arrWebhookUrl" regenerate="regenerateArrWebhookSecret">
            <flux:text size="sm" variant="subtle">{{ __('Used by Sonarr, Radarr, and Seerr.') }}</flux:text>
        </x-settings.webhook-url>
    </x-pages::settings.layout>
</section>
