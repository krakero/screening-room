<?php

use App\Concerns\ManagesWebhookSecret;
use App\Jobs\SyncPlexLibrary;
use App\Models\PlexLibraryItem;
use App\Services\Plex\PlexClient;
use App\Services\Plex\PlexDiscovery;
use App\Support\IntegrationSettings;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Plex settings')] class extends Component
{
    use ManagesWebhookSecret;

    public string $plexUrl = '';

    public string $plexToken = '';

    public string $plexAccountId = '';

    public bool $plexTokenConfigured = false;

    public string $plexWebhookUrl = '';

    /** @var array<int, array{id: int, name: string, label: string}> */
    public array $plexAccounts = [];

    /** @var array<int, array{label: string, ready: bool, needs?: string, note?: string}> */
    public array $features = [];

    public string $plexAccountName = '';

    public bool $plexManualOverride = true;

    /** @var array<int, array{machine_identifier: string, name: string, owned: bool, access_token: string, connections: array<int, array<string, mixed>>}> */
    public array $plexServers = [];

    public string $plexSelectedMachineIdentifier = '';

    public string $plexSelectedServerName = '';

    public string $plexConnectionMode = '';

    public ?int $plexConnectionLatencyMs = null;

    public ?int $plexSignInPinId = null;

    public string $plexSignInAuthUrl = '';

    public bool $plexSignInPolling = false;

    public int $plexSignInAttempts = 0;

    private const PLEX_SIGN_IN_MAX_ATTEMPTS = 40;

    public function mount(IntegrationSettings $settings): void
    {
        $this->plexUrl = (string) $settings->get('plex.url', '');
        $this->plexAccountId = (string) $settings->get('plex.account_id', '');
        $this->plexTokenConfigured = $settings->configured('plex.token');
        $this->plexWebhookUrl = $this->ensureWebhookUrl($settings, 'plex.webhook_secret', 'plex');
        $this->features = $this->buildFeatures($settings);
        $this->plexAccounts = $this->loadPlexAccounts($settings);

        $this->plexAccountName = (string) $settings->get('plex.account_name', '');
        $this->plexManualOverride = (bool) $settings->get('plex.manual_override', true);
        $this->plexServers = (array) $settings->get('plex.servers', []);
        $this->plexSelectedMachineIdentifier = (string) $settings->get('plex.selected_machine_identifier', '');
        $this->plexSelectedServerName = (string) $settings->get('plex.selected_server_name', '');
        $this->plexConnectionMode = (string) $settings->get('plex.connection_mode', '');
        $this->plexConnectionLatencyMs = $settings->get('plex.connection_latency_ms');
    }

    /**
     * Kick off a "Sign in with Plex" PIN: create it, open the auth page in a new tab, and start
     * polling. Capped by PLEX_SIGN_IN_MAX_ATTEMPTS so a poll that never resolves stops on its own.
     */
    public function startPlexSignIn(PlexDiscovery $discovery): void
    {
        try {
            $pin = $discovery->createPin();
        } catch (Throwable) {
            Flux::toast(variant: 'danger', text: __('Could not start Plex sign-in. Try again in a moment.'));

            return;
        }

        $this->plexSignInPinId = $pin['id'];
        $this->plexSignInAuthUrl = $pin['auth_url'];
        $this->plexSignInAttempts = 0;
        $this->plexSignInPolling = true;

        $this->dispatch('plex-auth-opened', url: $pin['auth_url']);
    }

    public function pollPlexSignIn(PlexDiscovery $discovery, IntegrationSettings $settings): void
    {
        if (! $this->plexSignInPolling || $this->plexSignInPinId === null) {
            return;
        }

        $this->plexSignInAttempts++;

        try {
            $token = $discovery->checkPin($this->plexSignInPinId);
        } catch (Throwable) {
            $token = null;
        }

        if ($token === null) {
            if ($this->plexSignInAttempts >= self::PLEX_SIGN_IN_MAX_ATTEMPTS) {
                $this->plexSignInPolling = false;
                Flux::toast(variant: 'danger', text: __('Plex sign-in timed out. Try again.'));
            }

            return;
        }

        $this->plexSignInPolling = false;
        $settings->set('plex.token', $token);
        $this->plexToken = '';
        $this->plexTokenConfigured = true;

        $account = $discovery->account($token);

        if ($account !== null) {
            $accountName = filled($account['username']) ? $account['username'] : $account['email'];

            $settings->setMany([
                'plex.account_name' => $accountName,
                'plex.account_uuid' => $account['uuid'],
                'plex.account_plex_id' => $account['id'],
            ]);
            $this->plexAccountName = $accountName;
        }

        try {
            $servers = $discovery->servers($token);
        } catch (Throwable) {
            $servers = [];
        }

        $settings->set('plex.servers', $servers);
        $this->plexServers = $servers;
        $this->features = $this->buildFeatures($settings);
        $this->plexAccounts = $this->loadPlexAccounts($settings);

        Flux::toast(variant: 'success', text: __('Signed in to Plex — pick a server below.'));
    }

    /**
     * Pick a discovered server: store its identity, run connection selection across its
     * connections, and switch off manual override so future refreshes stay automatic.
     */
    public function selectPlexServer(string $machineIdentifier, PlexDiscovery $discovery, IntegrationSettings $settings): void
    {
        $server = collect($this->plexServers)->firstWhere('machine_identifier', $machineIdentifier);

        if ($server === null) {
            return;
        }

        $token = (string) ($server['access_token'] ?? $settings->get('plex.token'));

        $winner = $discovery->selectConnection($server, $machineIdentifier, $token);

        if ($winner === null) {
            Flux::toast(variant: 'danger', text: __('Could not connect to :name yet — make sure it\'s online and try again.', ['name' => $server['name']]));

            return;
        }

        $settings->setMany([
            'plex.token' => $token,
            'plex.selected_machine_identifier' => $machineIdentifier,
            'plex.selected_server_name' => $server['name'],
            'plex.url' => $winner['url'],
            'plex.connection_mode' => $winner['mode'],
            'plex.connection_latency_ms' => $winner['latency_ms'],
            'plex.manual_override' => false,
            'plex.last_connection_check_at' => now()->toIso8601String(),
        ]);

        $this->plexUrl = $winner['url'];
        $this->plexSelectedMachineIdentifier = $machineIdentifier;
        $this->plexSelectedServerName = $server['name'];
        $this->plexConnectionMode = $winner['mode'];
        $this->plexConnectionLatencyMs = $winner['latency_ms'];
        $this->plexManualOverride = false;
        $this->features = $this->buildFeatures($settings);

        Flux::toast(variant: 'success', text: __('Connected to :name (:mode).', ['name' => $server['name'], 'mode' => $winner['mode']]));
    }

    public function redetectConnection(PlexDiscovery $discovery, IntegrationSettings $settings): void
    {
        $ok = $discovery->reselectConnection(force: true);

        if (! $ok) {
            Flux::toast(variant: 'danger', text: __('Could not reach the selected server on any connection.'));

            return;
        }

        $this->plexUrl = (string) $settings->get('plex.url', '');
        $this->plexConnectionMode = (string) $settings->get('plex.connection_mode', '');
        $this->plexConnectionLatencyMs = $settings->get('plex.connection_latency_ms');

        Flux::toast(variant: 'success', text: __('Reconnected via :mode.', ['mode' => $this->plexConnectionMode]));
    }

    /**
     * The server's accounts, for the picker — only fetched when URL + Token are configured, and
     * silently empty (falling back to the free-text field) if the lookup fails.
     *
     * @return array<int, array{id: int, name: string, label: string}>
     */
    private function loadPlexAccounts(IntegrationSettings $settings): array
    {
        if (! $settings->configured('plex.url', 'plex.token')) {
            return [];
        }

        try {
            $accounts = app(PlexClient::class)->accounts();
        } catch (Throwable) {
            return [];
        }

        return collect($accounts)
            ->map(fn (array $account): array => [
                'id' => $account['id'],
                'name' => $account['name'],
                'label' => $account['id'] === 1
                    ? __(':name (owner)', ['name' => $account['name']])
                    : __(':name (:id)', ['name' => $account['name'], 'id' => $account['id']]),
            ])
            ->all();
    }

    /**
     * @return array<int, array{label: string, ready: bool, needs?: string, note?: string}>
     */
    private function buildFeatures(IntegrationSettings $settings): array
    {
        return [
            [
                'label' => __('Scrobbling movies via webhook'),
                'ready' => true,
                'note' => __('Ready as soon as the URL below is pasted into Plex (Plex Pass) — no server URL, token, or account needed.'),
            ],
            [
                'label' => __('Scrobbling TV episodes via webhook'),
                'ready' => true,
                'note' => __('Also works from the webhook alone — the episode carries its own ids in the payload.'),
            ],
            [
                'label' => __('Catch-up polling every 15 min'),
                'ready' => $settings->configured('plex.url', 'plex.token'),
                'needs' => __('Server URL, Token'),
            ],
            [
                'label' => __('Test connection'),
                'ready' => $settings->configured('plex.url', 'plex.token'),
                'needs' => __('Server URL, Token'),
            ],
        ];
    }

    /**
     * @return array{status: string, items_indexed?: int, finished_at?: string, error?: string}|null
     */
    public function getLibraryIndexProperty(): ?array
    {
        return app(IntegrationSettings::class)->get('plex.library_index');
    }

    public function getLibraryIndexCountProperty(): int
    {
        return PlexLibraryItem::query()->count();
    }

    public function getPlexConfiguredProperty(): bool
    {
        return app(IntegrationSettings::class)->configured('plex.url', 'plex.token');
    }

    public function syncLibrary(): void
    {
        SyncPlexLibrary::dispatch();

        Flux::toast(variant: 'success', text: __('Library sync started — this can take a minute for a large library.'));
    }

    public function savePlex(IntegrationSettings $settings): void
    {
        $this->persistPlex($settings);

        Flux::toast(variant: 'success', text: __('Plex settings saved.'));
    }

    private function persistPlex(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'plexUrl' => ['nullable', 'url'],
            'plexToken' => ['nullable', 'string'],
            'plexAccountId' => ['nullable', 'string'],
        ]);

        $settings->setMany([
            'plex.url' => $validated['plexUrl'] ?: null,
            'plex.account_id' => $validated['plexAccountId'] ?: null,
            'plex.manual_override' => $this->plexManualOverride,
        ]);

        if (filled($validated['plexToken'])) {
            $settings->set('plex.token', $validated['plexToken']);
            $this->plexTokenConfigured = true;
        }

        $this->plexToken = '';
        $this->features = $this->buildFeatures($settings);
        $this->plexAccounts = $this->loadPlexAccounts($settings);
    }

    /**
     * Flip the manual-override switch on its own (without needing the Save button), so turning
     * discovery back on immediately re-enables auto-selection.
     */
    public function toggleManualOverride(IntegrationSettings $settings): void
    {
        $this->plexManualOverride = ! $this->plexManualOverride;
        $settings->set('plex.manual_override', $this->plexManualOverride);
    }

    public function regeneratePlexWebhookSecret(IntegrationSettings $settings): void
    {
        $this->plexWebhookUrl = $this->regenerateWebhookUrl($settings, 'plex.webhook_secret', 'plex');

        Flux::toast(variant: 'success', text: __('Plex webhook URL regenerated.'));
    }

    public function testPlex(IntegrationSettings $settings): void
    {
        $this->persistPlex($settings);

        $plex = app(PlexClient::class);

        try {
            $ok = $plex->testConnection();
        } catch (Throwable) {
            $ok = false;
        }

        if (! $ok) {
            Flux::toast(variant: 'danger', text: __('Plex connection failed.'));

            return;
        }

        if (blank($settings->get('plex.account_id'))) {
            Flux::toast(variant: 'success', text: __('Plex connection successful.'));

            return;
        }

        try {
            $resolved = $plex->resolveAccountId();
        } catch (Throwable) {
            $resolved = null;
        }

        if ($resolved === null) {
            Flux::toast(variant: 'danger', text: __('Plex connected, but the configured account was not found.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Plex connection successful.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Plex settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Plex')" :subheading="__('Records plays from your Plex server, via webhook or polling')">
        <flux:callout icon="information-circle" class="mb-6">
            <flux:callout.text>
                {{ __('How Plex scrobbling works: Plex sends a webhook every time you finish something, and we match it to a title using the ids already in that webhook — no server access needed. The Server URL and Token below are optional: they let the app also call your Plex server directly, for catch-up polling and as a fallback lookup when a webhook alone can\'t identify something.') }}
            </flux:callout.text>
        </flux:callout>

        <x-settings.feature-list :items="$features" />

        <flux:card variant="outline" :highlight="false" class="border-line bg-surface mb-6"
            x-data
            x-on:plex-auth-opened.window="window.open($event.detail.url, '_blank')"
            wire:poll.2s="pollPlexSignIn"
        >
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="sm">{{ __('Sign in with Plex') }}</flux:heading>
                    @if ($plexAccountName !== '')
                        <flux:text size="sm" variant="subtle">{{ __('Connected as :name.', ['name' => $plexAccountName]) }}</flux:text>
                    @else
                        <flux:text size="sm" variant="subtle">{{ __('Discovers your servers automatically — no need to find or paste a server URL.') }}</flux:text>
                    @endif
                </div>
                <flux:button size="sm" wire:click="startPlexSignIn" wire:loading.attr="disabled" wire:target="startPlexSignIn" :disabled="$plexSignInPolling">
                    @if ($plexSignInPolling)
                        <span class="inline-flex items-center gap-2"><flux:icon.loading variant="micro" /> {{ __('Waiting for sign-in…') }}</span>
                    @else
                        {{ $plexAccountName !== '' ? __('Sign in again') : __('Sign in with Plex') }}
                    @endif
                </flux:button>
            </div>

            @if ($plexSignInPolling && $plexSignInAuthUrl !== '')
                <flux:text size="sm" variant="subtle" class="mt-3">
                    {{ __('A tab should have opened at app.plex.tv. If it didn\'t,') }}
                    <flux:link href="{{ $plexSignInAuthUrl }}" target="_blank" rel="noopener">{{ __('open it manually') }}</flux:link>.
                </flux:text>
            @endif

            @if ($plexServers !== [])
                <div class="mt-4 space-y-2">
                    @foreach ($plexServers as $server)
                        <div class="flex items-center justify-between gap-4 rounded-md border border-line p-3">
                            <div>
                                <flux:text>
                                    {{ $server['name'] }}
                                    @if ($server['owned'])
                                        <flux:badge size="sm" color="zinc">{{ __('owned') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('shared') }}</flux:badge>
                                    @endif
                                    @if (! ($server['presence'] ?? true))
                                        <flux:badge size="sm" color="red">{{ __('offline') }}</flux:badge>
                                    @endif
                                </flux:text>
                                @if ($server['machine_identifier'] === $plexSelectedMachineIdentifier)
                                    <flux:text size="sm" variant="subtle">
                                        {{ __('Connected via :mode', ['mode' => $plexConnectionMode ?: __('unknown')]) }}
                                        @if ($plexConnectionLatencyMs !== null)
                                            ({{ $plexConnectionLatencyMs }}ms)
                                        @endif
                                    </flux:text>
                                @endif
                            </div>
                            @if ($server['machine_identifier'] === $plexSelectedMachineIdentifier)
                                <flux:button size="sm" variant="ghost" wire:click="redetectConnection" wire:loading.attr="disabled">{{ __('Re-detect connection') }}</flux:button>
                            @else
                                <flux:button size="sm" wire:click="selectPlexServer('{{ $server['machine_identifier'] }}')" wire:loading.attr="disabled">{{ __('Use this server') }}</flux:button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </flux:card>

        @if ($this->plexConfigured)
            <flux:card variant="outline" :highlight="false" class="border-line bg-surface mb-6">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <flux:text>
                            @if (($this->libraryIndex['status'] ?? null) === 'failed')
                                <span class="text-red-500">{{ __('Library sync failed: :error', ['error' => $this->libraryIndex['error'] ?? __('unknown error')]) }}</span>
                            @elseif ($this->libraryIndex)
                                {{ __(':count items indexed — last synced :time', ['count' => $this->libraryIndexCount, 'time' => $this->libraryIndex['finished_at']]) }}
                            @else
                                {{ __('Library not indexed yet. Sync it so Watch on Plex buttons can find your library.') }}
                            @endif
                        </flux:text>
                    </div>
                    <flux:button size="sm" wire:click="syncLibrary" wire:loading.attr="disabled">{{ __('Sync library now') }}</flux:button>
                </div>
            </flux:card>
        @endif

        <flux:switch wire:click="toggleManualOverride" :checked="$plexManualOverride" :label="__('Advanced: manual URL override')" :description="__('Enter the server URL yourself and disable automatic connection selection.')" class="mb-6" />

        <form wire:submit="savePlex" class="space-y-6">
            <flux:input wire:model="plexUrl" :label="__('Server URL')" placeholder="http://plex.local:32400" :disabled="! $plexManualOverride" :description="$plexManualOverride ? __('Optional — used for catch-up polling, fallback lookups, and the test connection button; not needed for webhook scrobbling. Use http://<ip>:32400 on your LAN, or your https://…plex.direct:32400 address (Plex\'s certificate isn\'t valid for a plain LAN ip over https).') : __('Managed automatically by Plex sign-in / server discovery above. Turn on the override to edit it directly.')" />
            <x-settings.masked-input wire:model="plexToken" :label="__('Token')" :configured="$plexTokenConfigured" :description="__('Optional. Open any item in Plex Web, choose \'Get Info\' → \'View XML\', and copy the value after X-Plex-Token= in the URL. Pairs with the Server URL above; not needed for webhook scrobbling. Alternatively, use \'Sign in with Plex\' above.')" />

            @if ($plexAccounts !== [])
                <flux:select wire:model="plexAccountId" :label="__('Account (optional)')" :description="__('Filters which plays get recorded to just this Plex account; leave blank to record plays from everyone on your server.')">
                    <flux:select.option value="">{{ __('Everyone on this server') }}</flux:select.option>
                    @foreach ($plexAccounts as $account)
                        <flux:select.option value="{{ $account['id'] }}">{{ $account['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <flux:input wire:model="plexAccountId" :label="__('Account ID (optional)')" :description="__('Filters which plays get recorded to just this Plex account; leave blank to record plays from everyone on your server. The server owner is always account 1 — other users\' numeric ids are listed at http://<server>:32400/accounts?X-Plex-Token=<token>, or pick from a list here once the Server URL + Token above are saved.')" />
            @endif

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <x-settings.test-connection-button :enabled="class_exists(PlexClient::class)" action="testPlex" />
            </div>
        </form>

        <x-settings.webhook-url :url="$plexWebhookUrl" regenerate="regeneratePlexWebhookSecret">
            <flux:text size="sm" variant="subtle">{{ __('Paste into Plex Settings → Webhooks (requires Plex Pass).') }}</flux:text>
        </x-settings.webhook-url>
    </x-pages::settings.layout>
</section>
