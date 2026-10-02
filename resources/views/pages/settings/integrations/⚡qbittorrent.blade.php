<?php

use App\Services\Qbittorrent\QbittorrentClient;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('qBittorrent settings')] class extends Component
{
    public string $qbittorrentUrl = '';

    public string $qbittorrentUsername = '';

    public string $qbittorrentPassword = '';

    public bool $qbittorrentPasswordConfigured = false;

    /** @var array<int, array{label: string, ready: bool, needs?: string, note?: string}> */
    public array $features = [];

    public function mount(IntegrationSettings $settings): void
    {
        $this->qbittorrentUrl = (string) $settings->get('qbittorrent.url', '');
        $this->qbittorrentUsername = (string) $settings->get('qbittorrent.username', '');
        $this->qbittorrentPasswordConfigured = $settings->configured('qbittorrent.password');
        $this->features = $this->buildFeatures($settings);
    }

    /**
     * @return array<int, array{label: string, ready: bool, needs?: string, note?: string}>
     */
    private function buildFeatures(IntegrationSettings $settings): array
    {
        $ready = $settings->configured('qbittorrent.url');

        return [
            [
                'label' => __('Live torrent progress on the Downloads page'),
                'ready' => $ready,
                'needs' => __('Server URL'),
            ],
            [
                'label' => __('Test connection'),
                'ready' => $ready,
                'needs' => __('Server URL'),
            ],
        ];
    }

    public function saveQbittorrent(IntegrationSettings $settings): void
    {
        $this->persistQbittorrent($settings);

        Flux::toast(variant: 'success', text: __('qBittorrent settings saved.'));
    }

    private function persistQbittorrent(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'qbittorrentUrl' => ['required', 'url'],
            'qbittorrentUsername' => ['nullable', 'string'],
            'qbittorrentPassword' => ['nullable', 'string'],
        ]);

        $settings->set('qbittorrent.url', rtrim($validated['qbittorrentUrl'], '/'));
        $settings->set('qbittorrent.username', $validated['qbittorrentUsername'] ?: null);

        if (filled($validated['qbittorrentPassword'])) {
            $settings->set('qbittorrent.password', $validated['qbittorrentPassword']);
            $this->qbittorrentPasswordConfigured = true;
        }

        Cache::forget('qbittorrent:sid');

        $this->qbittorrentUrl = (string) $settings->get('qbittorrent.url');
        $this->qbittorrentPassword = '';
        $this->features = $this->buildFeatures($settings);
    }

    public function testQbittorrent(IntegrationSettings $settings): void
    {
        $this->persistQbittorrent($settings);

        try {
            $client = app(QbittorrentClient::class);
            $ok = $client->testConnection();
            $version = $ok ? $client->version() : null;
        } catch (Throwable) {
            $ok = false;
            $version = null;
        }

        Flux::toast(
            variant: $ok ? 'success' : 'danger',
            text: $ok
                ? __('qBittorrent connection successful (:version).', ['version' => $version])
                : __('qBittorrent connection failed.'),
        );
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('qBittorrent settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('qBittorrent')" :subheading="__('Show live torrent progress')">
        <x-settings.feature-list :items="$features" />

        <form wire:submit="saveQbittorrent" class="space-y-6">
            <flux:input wire:model="qbittorrentUrl" :label="__('Server URL')" placeholder="http://qbittorrent.local:8080" :description="__('Your qBittorrent Web UI address, e.g. http://192.168.1.10:8080.')" />
            <flux:input wire:model="qbittorrentUsername" :label="__('Username')" :description="__('Leave blank if qBittorrent bypasses authentication for this server.')" />
            <x-settings.masked-input wire:model="qbittorrentPassword" :label="__('Password')" :configured="$qbittorrentPasswordConfigured" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <x-settings.test-connection-button :enabled="true" action="testQbittorrent" />
            </div>
        </form>
    </x-pages::settings.layout>
</section>
