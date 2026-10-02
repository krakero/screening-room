<?php

use App\Concerns\TestsIntegrationConnection;
use App\Services\Pushover\PushoverClient;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pushover settings')] class extends Component
{
    use TestsIntegrationConnection;

    public string $pushoverUserKey = '';

    public string $pushoverAppToken = '';

    public bool $pushoverUserKeyConfigured = false;

    public bool $pushoverAppTokenConfigured = false;

    /** @var array<int, array{label: string, ready: bool, needs?: string, note?: string}> */
    public array $features = [];

    public function mount(IntegrationSettings $settings): void
    {
        $this->pushoverUserKeyConfigured = $settings->configured('pushover.user_key');
        $this->pushoverAppTokenConfigured = $settings->configured('pushover.app_token');
        $this->features = $this->buildFeatures($settings);
    }

    /**
     * @return array<int, array{label: string, ready: bool, needs?: string, note?: string}>
     */
    private function buildFeatures(IntegrationSettings $settings): array
    {
        $ready = $settings->configured('pushover.user_key', 'pushover.app_token');

        return [
            [
                'label' => __('Notify when a requested title becomes available'),
                'ready' => $ready,
                'needs' => __('User key, App token'),
            ],
            [
                'label' => __("Daily digest of today's episodes (9am)"),
                'ready' => $ready,
                'needs' => __('User key, App token'),
                'note' => __('Skipped on days with nothing airing.'),
            ],
            [
                'label' => __('Test connection'),
                'ready' => $ready,
                'needs' => __('User key, App token'),
            ],
        ];
    }

    public function savePushover(IntegrationSettings $settings): void
    {
        $this->persistPushover($settings);

        Flux::toast(variant: 'success', text: __('Pushover settings saved.'));
    }

    private function persistPushover(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'pushoverUserKey' => ['nullable', 'string'],
            'pushoverAppToken' => ['nullable', 'string'],
        ]);

        if (filled($validated['pushoverUserKey'])) {
            $settings->set('pushover.user_key', $validated['pushoverUserKey']);
            $this->pushoverUserKeyConfigured = true;
        }

        if (filled($validated['pushoverAppToken'])) {
            $settings->set('pushover.app_token', $validated['pushoverAppToken']);
            $this->pushoverAppTokenConfigured = true;
        }

        $this->pushoverUserKey = '';
        $this->pushoverAppToken = '';
        $this->features = $this->buildFeatures($settings);
    }

    public function testPushover(IntegrationSettings $settings): void
    {
        $this->persistPushover($settings);

        if (! class_exists(PushoverClient::class)) {
            return;
        }

        $this->testConnection(app(PushoverClient::class), __('Pushover'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Pushover settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Pushover')" :subheading="__('Push notifications for available titles and daily air dates')">
        <x-settings.feature-list :items="$features" />

        <form wire:submit="savePushover" class="space-y-6">
            <x-settings.masked-input wire:model="pushoverUserKey" :label="__('User key')" :configured="$pushoverUserKeyConfigured" :description="__('From your Pushover dashboard at pushover.net.')" />
            <x-settings.masked-input wire:model="pushoverAppToken" :label="__('App token')" :configured="$pushoverAppTokenConfigured" :description="__('The API token for an application you\'ve created at pushover.net/apps.')" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <x-settings.test-connection-button :enabled="class_exists(PushoverClient::class)" action="testPushover" />
            </div>
        </form>
    </x-pages::settings.layout>
</section>
