<?php

use App\Services\Qbittorrent\QbittorrentClient;
use App\Services\Qbittorrent\QbittorrentException;
use App\Services\Qbittorrent\Torrent;
use App\Enums\TorrentState;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Downloads')] class extends Component
{
    #[Computed]
    public function isConfigured(): bool
    {
        return app(QbittorrentClient::class)->configured();
    }

    #[Computed]
    public function webUiUrl(): ?string
    {
        return app(QbittorrentClient::class)->webUiUrl();
    }

    /**
     * @return array{torrents: Collection<int, Torrent>, error: ?string}
     */
    #[Computed]
    public function snapshot(): array
    {
        if (! $this->isConfigured) {
            return ['torrents' => collect(), 'error' => null];
        }

        try {
            return ['torrents' => app(QbittorrentClient::class)->torrents(), 'error' => null];
        } catch (QbittorrentException $exception) {
            return ['torrents' => collect(), 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return array{downloading: int, seeding: int, speed: int}
     */
    #[Computed]
    public function summary(): array
    {
        $torrents = $this->snapshot['torrents'];

        return [
            'downloading' => $torrents->where('state', TorrentState::Downloading)->count(),
            'seeding' => $torrents->where('state', TorrentState::Seeding)->count(),
            'speed' => (int) $torrents->sum('dlspeed'),
        ];
    }

    public function formatSpeed(int $bytesPerSecond): string
    {
        return Number::fileSize($bytesPerSecond, precision: 1).'/s';
    }

    public function formatEta(Torrent $torrent): string
    {
        if ($torrent->eta === null || $torrent->progress >= 1) {
            return '—';
        }

        return CarbonInterval::seconds($torrent->eta)->cascade()->forHumans(['short' => true, 'parts' => 1]);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl" wire:poll.5s.visible>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">{{ __('Downloads') }}</flux:heading>
            <flux:subheading>{{ __('Live torrents from qBittorrent.') }}</flux:subheading>
        </div>

        @if ($this->isConfigured && $this->webUiUrl)
            <flux:button :href="$this->webUiUrl" target="_blank" rel="noopener" icon-trailing="arrow-top-right-on-square">
                {{ __('Open qBittorrent') }}
            </flux:button>
        @endif
    </div>

    @if (! $this->isConfigured)
        <x-media.empty-state icon="arrow-down-tray" :heading="__('qBittorrent isn’t connected')">
            {{ __('Add your qBittorrent URL to see live downloads here.') }}
            <div class="mt-3">
                <flux:button :href="route('settings.integrations.qbittorrent')" wire:navigate variant="primary">
                    {{ __('Set up qBittorrent') }}
                </flux:button>
            </div>
        </x-media.empty-state>
    @else
        @if ($this->snapshot['error'])
            <flux:callout variant="danger" icon="exclamation-triangle" data-test="downloads-error">
                <flux:callout.heading>{{ __('Couldn’t reach qBittorrent') }}</flux:callout.heading>
                <flux:callout.text>{{ $this->snapshot['error'] }}</flux:callout.text>
            </flux:callout>
        @elseif ($this->snapshot['torrents']->isEmpty())
            <x-media.empty-state icon="arrow-down-tray" :heading="__('Nothing downloading')">
                {{ __('Torrents added to qBittorrent will show up here.') }}
            </x-media.empty-state>
        @else
            <p class="text-sm text-ink-muted" data-test="downloads-summary">
                {{ __(':count downloading', ['count' => $this->summary['downloading']]) }}
                · {{ __(':count seeding', ['count' => $this->summary['seeding']]) }}
                · ↓ {{ $this->formatSpeed($this->summary['speed']) }}
            </p>

            <ul class="flex flex-col divide-y divide-line rounded-xl border border-line">
                @foreach ($this->snapshot['torrents'] as $torrent)
                    <li
                        wire:key="torrent-{{ $torrent->hash }}"
                        class="grid gap-x-4 gap-y-2 p-4 md:grid-cols-[minmax(0,2fr)_auto_minmax(8rem,1fr)_auto_auto] md:items-center"
                    >
                        <div class="flex min-w-0 flex-col gap-1">
                            <span class="truncate font-medium text-ink" title="{{ $torrent->name }}">{{ $torrent->name }}</span>
                            @if ($torrent->category)
                                <span><x-media.chip>{{ $torrent->category }}</x-media.chip></span>
                            @endif
                        </div>

                        <div>
                            <flux:badge :color="$torrent->state->color()" size="sm">{{ $torrent->state->label() }}</flux:badge>
                        </div>

                        <div class="flex items-center gap-2">
                            <x-media.progress :value="$torrent->percent()" class="flex-1" />
                            <span class="w-10 text-right text-xs tabular-nums text-ink-muted">{{ $torrent->percent() }}%</span>
                        </div>

                        <div class="flex gap-3 text-xs tabular-nums text-ink-muted md:min-w-40 md:justify-end">
                            @if ($torrent->dlspeed > 0)
                                <span>↓ {{ $this->formatSpeed($torrent->dlspeed) }}</span>
                            @endif
                            @if ($torrent->upspeed > 0)
                                <span>↑ {{ $this->formatSpeed($torrent->upspeed) }}</span>
                            @endif
                        </div>

                        <div class="flex gap-3 text-xs tabular-nums text-ink-muted md:min-w-28 md:justify-end">
                            <span title="{{ __('ETA') }}">{{ $this->formatEta($torrent) }}</span>
                            <span>{{ \Illuminate\Support\Number::fileSize($torrent->size, precision: 1) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
