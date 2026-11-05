<?php

use App\Enums\UpdateChannel;
use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdaterClient;
use App\Support\AppVersion;
use App\Support\DisplayTimezone;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Updates')] class extends Component
{
    public bool $showChannelModal = false;

    #[Locked]
    public ?string $pendingChannel = null;

    public bool $showDevelopWarningModal = false;

    #[Locked]
    public ?string $updatingRequestId = null;

    public function getCurrentVersionProperty(): array
    {
        $version = AppVersion::current();

        return [
            'version' => $version->version,
            'channel' => $version->channel,
            'commit' => $version->commit,
        ];
    }

    public function getUpdaterAvailableProperty(): bool
    {
        return app(UpdaterClient::class)->isAvailable();
    }

    public function getAvailableUpdateProperty(): ?array
    {
        $update = app(UpdateChecker::class)->check();

        if ($update === null) {
            return null;
        }

        return [
            'version' => $update->version,
            'summary' => $update->summary,
            'url' => $update->url,
            'published_at' => $update->published_at,
            'channel' => $update->channel,
        ];
    }

    public function getPreUpdateDumpsProperty(): array
    {
        return app(UpdaterClient::class)->preUpdateDumps();
    }

    public function getUpdatingStatusProperty(): ?array
    {
        return app(UpdaterClient::class)->currentUpdate();
    }

    public function checkForUpdates(): void
    {
        app(UpdateChecker::class)->check(force: true);

        Flux::toast(variant: 'success', text: __('Checked for updates.'));
    }

    public function updateNow(): void
    {
        if (! $this->updaterAvailable) {
            Flux::toast(variant: 'danger', text: __('Updater is not running.'));

            return;
        }

        if ($this->isUpdating()) {
            Flux::toast(variant: 'info', text: __('An update is already in progress.'));

            return;
        }

        $channel = $this->currentVersion['channel'];

        try {
            $requestId = app(UpdaterClient::class)->requestUpdate($channel, 'update');
        } catch (\Exception $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->updatingRequestId = $requestId;
    }

    public function confirmChannelSwitch(string $channel): void
    {
        $newChannel = UpdateChannel::from($channel);

        if ($newChannel === $this->currentVersion['channel']) {
            return;
        }

        $this->pendingChannel = $channel;

        if ($newChannel === UpdateChannel::Develop) {
            $this->showDevelopWarningModal = true;
        } else {
            $this->showChannelModal = true;
        }
    }

    public function cancelQueued(): void
    {
        app(UpdaterClient::class)->cancelQueued();
    }

    private function isUpdating(): bool
    {
        $state = $this->updatingStatus['state'] ?? null;

        return in_array($state, ['queued', 'starting', 'pulling', 'recreating', 'waiting', 'rolling_back'], true);
    }

    public function switchChannel(): void
    {
        if (! $this->updaterAvailable || $this->pendingChannel === null) {
            $this->closeChannelModal();
            $this->closeDevelopWarningModal();
            Flux::toast(variant: 'danger', text: __('Updater is not running.'));

            return;
        }

        if ($this->isUpdating()) {
            $this->closeChannelModal();
            $this->closeDevelopWarningModal();
            Flux::toast(variant: 'info', text: __('An update is already in progress.'));

            return;
        }

        $channel = UpdateChannel::from($this->pendingChannel);

        try {
            $requestId = app(UpdaterClient::class)->requestUpdate($channel, 'switch');
        } catch (\Exception $e) {
            $this->closeChannelModal();
            $this->closeDevelopWarningModal();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->closeChannelModal();
        $this->closeDevelopWarningModal();
        $this->updatingRequestId = $requestId;
        $this->pendingChannel = null;
    }

    public function closeChannelModal(): void
    {
        $this->showChannelModal = false;
        $this->pendingChannel = null;
    }

    public function closeDevelopWarningModal(): void
    {
        $this->showDevelopWarningModal = false;
        $this->pendingChannel = null;
    }

    public function reloadAfterUpdate(): void
    {
        redirect()->route('settings.updates');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Updates') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Updates')" :subheading="__('Manage your application version and update channel')">
        @php $isInProgress = $this->isUpdating(); @endphp

        @if ($this->updatingStatus)
            <div class="mb-6" x-data="{
                status: @js($this->updatingStatus),
                heartbeatOk: @js($this->updaterAvailable),
                seenInProgress: false,
                pollInterval: null,
                isInProgress(state) {
                    return ['queued', 'starting', 'pulling', 'recreating', 'waiting', 'rolling_back'].includes(state);
                },
                async fetchStatus() {
                    try {
                        const response = await fetch('{{ route('settings.updates.status') }}');
                        const data = await response.json();
                        this.heartbeatOk = data.heartbeat_ok;
                        if (data.current) {
                            const prevState = this.status ? this.status.state : null;
                            this.status = data.current;
                            if (this.isInProgress(this.status.state)) {
                                this.seenInProgress = true;
                            }
                            if (this.status.state === 'done' && this.seenInProgress && prevState !== 'done') {
                                clearInterval(this.pollInterval);
                                setTimeout(() => window.location.reload(), 1000);
                            } else if (this.status.state === 'rolled_back' || this.status.state === 'failed') {
                                clearInterval(this.pollInterval);
                            }
                        }
                    } catch (e) {
                        // App is restarting, keep polling
                    }
                },
                init() {
                    if (this.status && this.isInProgress(this.status.state)) {
                        this.seenInProgress = true;
                    }
                    this.pollInterval = setInterval(() => this.fetchStatus(), 2000);
                }
            }" x-init="init()" wire:poll.2s>
                <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                    <div class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div x-show="isInProgress(status.state)" class="mt-1">
                                <svg class="size-5 animate-spin text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            <div class="flex-1 space-y-2">
                                <flux:heading size="lg" x-text="
                                    status.state === 'queued' ? '{{ __('Waiting for the updater…') }}' :
                                    status.state === 'starting' ? '{{ __('Starting update…') }}' :
                                    status.state === 'pulling' ? '{{ __('Downloading update…') }}' :
                                    status.state === 'recreating' ? '{{ __('Applying update…') }}' :
                                    status.state === 'waiting' ? '{{ __('Starting application…') }}' :
                                    status.state === 'rolling_back' ? '{{ __('Rolling back…') }}' :
                                    status.state === 'done' ? '{{ __('Update complete') }}' :
                                    status.state === 'rolled_back' ? '{{ __('Update failed, rolled back') }}' :
                                    '{{ __('Update failed') }}'
                                "></flux:heading>

                                <flux:text x-show="status.message" x-text="status.message" class="text-sm"></flux:text>

                                <div x-show="status.state === 'queued' && !heartbeatOk" class="rounded-md border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                                    <flux:text class="text-sm text-amber-700 dark:text-amber-400">
                                        {{ __('The updater isn\'t running, so this update hasn\'t started.') }}
                                    </flux:text>
                                    <div class="mt-2">
                                        <flux:button wire:click="cancelQueued" size="sm" variant="outline">{{ __('Cancel') }}</flux:button>
                                    </div>
                                </div>

                                <div x-show="status.state === 'done'" class="pt-2">
                                    <flux:button wire:click="reloadAfterUpdate">{{ __('Reload') }}</flux:button>
                                </div>

                                <div x-show="status.state === 'rolled_back' && status.dump" class="pt-2">
                                    <flux:text class="text-sm text-amber-600">
                                        {{ __('The update failed and the previous version was restored. A database dump was saved: ') }}
                                        <span x-text="status.dump" class="font-mono"></span>
                                    </flux:text>
                                </div>
                            </div>
                        </div>
                    </div>
                </flux:card>
            </div>
        @elseif (! $this->updaterAvailable)
            <flux:card variant="outline" :highlight="false" class="mb-6 border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950/30">
                <div class="space-y-3">
                    <flux:heading size="base">{{ __('Manual updates') }}</flux:heading>
                    <flux:text class="text-sm">
                        {{ __('The updater service is not running. To update manually, run these commands in your installation directory:') }}
                    </flux:text>
                    <div class="space-y-1">
                        <code class="block rounded bg-surface px-3 py-2 text-sm font-mono text-ink">docker compose pull</code>
                        <code class="block rounded bg-surface px-3 py-2 text-sm font-mono text-ink">docker compose up -d</code>
                    </div>
                </div>
            </flux:card>
        @endif

        <div class="space-y-6">
            <div>
                <flux:heading>{{ __('Current version') }}</flux:heading>
                <div class="mt-3 space-y-2">
                    <p class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-medium text-ink">{{ $this->currentVersion['version'] }}</span>
                        <flux:badge>{{ $this->currentVersion['channel']->label() }}</flux:badge>
                        @if ($this->currentVersion['commit'])
                            <span class="opacity-50">/</span>
                            <span class="font-mono text-xs text-ink-muted">{{ Str::limit($this->currentVersion['commit'], 7, '') }}</span>
                        @endif
                    </p>
                    <flux:button size="sm" wire:click="checkForUpdates" :disabled="! $this->updaterAvailable || $isInProgress">{{ __('Check now') }}</flux:button>
                </div>
            </div>

            @if ($this->availableUpdate)
                <flux:card variant="outline" :highlight="false" class="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950/30">
                    <div class="space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="space-y-1">
                                <flux:heading size="base">{{ __('Update available') }}</flux:heading>
                                <flux:text class="text-sm">
                                    {{ $this->availableUpdate['version'] }} · {{ $this->availableUpdate['summary'] }}
                                </flux:text>
                                @if ($this->availableUpdate['url'])
                                    <a href="{{ $this->availableUpdate['url'] }}" target="_blank" class="text-sm text-blue-600 hover:underline dark:text-blue-400">
                                        {{ __('View details') }}
                                    </a>
                                @endif
                            </div>
                            <flux:button variant="primary" size="sm" wire:click="updateNow" :disabled="! $this->updaterAvailable || $isInProgress">
                                @if ($isInProgress)
                                    <svg class="size-4 animate-spin -ml-0.5 mr-1.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{ __('Updating…') }}
                                @else
                                    {{ __('Update now') }}
                                @endif
                            </flux:button>
                        </div>
                    </div>
                </flux:card>
            @endif

            <div>
                <flux:heading>{{ __('Update channel') }}</flux:heading>
                <flux:text size="sm" class="mt-1">{{ __('Choose which updates to receive') }}</flux:text>

                <div class="mt-4 space-y-3">
                    <flux:radio.group>
                        <flux:radio
                            name="channel"
                            value="stable"
                            :checked="$this->currentVersion['channel'] === UpdateChannel::Stable"
                            wire:click="confirmChannelSwitch('stable')"
                            :disabled="! $this->updaterAvailable || $isInProgress"
                            :label="__('Stable')"
                            :description="__('Recommended. Stable releases tested for production use.')"
                        />

                        <flux:radio
                            name="channel"
                            value="develop"
                            :checked="$this->currentVersion['channel'] === UpdateChannel::Develop"
                            wire:click="confirmChannelSwitch('develop')"
                            :disabled="! $this->updaterAvailable || $isInProgress"
                            :label="__('Develop')"
                            :description="__('Latest features from the main branch. May be unstable.')"
                        />
                    </flux:radio.group>
                </div>
            </div>

            @if (count($this->preUpdateDumps) > 0)
                <div>
                    <flux:heading>{{ __('Pre-update dumps') }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __('Automatic database dumps created before updates') }}</flux:text>

                    <div class="mt-4 overflow-hidden rounded-lg border border-line">
                        @foreach ($this->preUpdateDumps as $dump)
                            <div wire:key="dump-{{ $dump['filename'] }}" class="flex items-center justify-between gap-3 p-4 {{ ! $loop->last ? 'border-b border-line' : '' }}">
                                <div class="min-w-0 space-y-1">
                                    <p class="truncate font-mono text-sm text-ink">{{ $dump['filename'] }}</p>
                                    <p class="flex flex-wrap items-center gap-2 text-xs text-ink-muted">
                                        <span>{{ Number::fileSize($dump['size']) }}</span>
                                        <span class="opacity-50">/</span>
                                        <span>{{ DisplayTimezone::local($dump['created_at'])->format('M j, Y g:ia') }}</span>
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>

    <flux:modal name="channel-modal" class="max-w-md md:min-w-md" @close="closeChannelModal" wire:model="showChannelModal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Switch to Stable channel') }}</flux:heading>
                <flux:text>{{ __('Switching channels will download and install the latest release from the Stable channel. The application will restart.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closeChannelModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="switchChannel">{{ __('Switch channel') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="develop-warning-modal" class="max-w-md md:min-w-md" @close="closeDevelopWarningModal" wire:model="showDevelopWarningModal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Switch to Develop channel') }}</flux:heading>
                <flux:text>{{ __('The Develop channel includes the latest features but may be unstable. If you need to roll back, restore from a pre-update dump in Settings > Backups.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closeDevelopWarningModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="switchChannel">{{ __('Switch anyway') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
