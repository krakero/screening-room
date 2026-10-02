<?php

use App\Concerns\ManagesWebhookSecret;
use App\Services\Plex\PlexClient;
use App\Services\Plex\PlexDiscovery;
use App\Support\IntegrationSettings;
use App\Support\SetupProgress;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::setup')] #[Title('Connect Plex — Setup')] class extends Component
{
    use ManagesWebhookSecret;

    public string $plexUrl = '';

    public string $plexToken = '';

    public string $plexAccountId = '';

    public bool $plexTokenConfigured = false;

    public string $plexWebhookUrl = '';

    public bool $showAdvanced = false;

    public string $plexAccountName = '';

    /** @var array<int, array{machine_identifier: string, name: string, owned: bool, presence: bool, access_token: string, connections: array<int, array<string, mixed>>}> */
    public array $plexServers = [];

    public string $plexSelectedMachineIdentifier = '';

    public ?int $plexSignInPinId = null;

    public string $plexSignInAuthUrl = '';

    public bool $plexSignInPolling = false;

    public int $plexSignInAttempts = 0;

    private const PLEX_SIGN_IN_MAX_ATTEMPTS = 40;

    public function mount(IntegrationSettings $settings, SetupProgress $progress): void
    {
        $this->plexUrl = (string) $settings->get('plex.url', '');
        $this->plexAccountId = (string) $settings->get('plex.account_id', '');
        $this->plexTokenConfigured = $settings->configured('plex.token');
        $this->plexWebhookUrl = $this->ensureWebhookUrl($settings, 'plex.webhook_secret', 'plex');
        $this->plexAccountName = (string) $settings->get('plex.account_name', '');
        $this->plexServers = (array) $settings->get('plex.servers', []);
        $this->plexSelectedMachineIdentifier = (string) $settings->get('plex.selected_machine_identifier', '');

        $progress->markReached('plex');
    }

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

        Flux::toast(variant: 'success', text: __('Signed in to Plex — pick a server below.'));
    }

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

        Flux::toast(variant: 'success', text: __('Connected to :name.', ['name' => $server['name']]));
    }

    public function savePlex(IntegrationSettings $settings): void
    {
        $validated = $this->validate([
            'plexUrl' => ['nullable', 'url'],
            'plexToken' => ['nullable', 'string'],
            'plexAccountId' => ['nullable', 'string'],
        ]);

        $settings->setMany([
            'plex.url' => $validated['plexUrl'] ?: null,
            'plex.account_id' => $validated['plexAccountId'] ?: null,
        ]);

        if (filled($validated['plexToken'])) {
            $settings->set('plex.token', $validated['plexToken']);
            $this->plexTokenConfigured = true;
        }

        $this->plexToken = '';

        Flux::toast(variant: 'success', text: __('Plex settings saved.'));
    }

    public function regeneratePlexWebhookSecret(IntegrationSettings $settings): void
    {
        $this->plexWebhookUrl = $this->regenerateWebhookUrl($settings, 'plex.webhook_secret', 'plex');
    }

    public function testPlex(): void
    {
        try {
            $ok = class_exists(PlexClient::class) && app(PlexClient::class)->testConnection();
        } catch (\Throwable) {
            $ok = false;
        }

        Flux::toast(variant: $ok ? 'success' : 'danger', text: $ok ? __('Plex connection successful.') : __('Plex connection failed.'));
    }

    public function continueSetup(): void
    {
        $this->redirectRoute('setup.requests', navigate: true);
    }
}; ?>

<x-setup.layout step="plex" :title="__('Connect Plex')" :subtitle="__('Optional — automatically log plays as you watch.')" back-route="setup.import" skip-route="setup.requests">
    <flux:callout icon="information-circle" class="mb-6">
        <flux:callout.text>{{ __('Paste this URL into Plex → Settings → Webhooks (requires Plex Pass). That\'s all that\'s needed for scrobbling — signing in below is only for catch-up polling and account filtering.') }}</flux:callout.text>
    </flux:callout>

    <x-settings.webhook-url :url="$plexWebhookUrl" regenerate="regeneratePlexWebhookSecret" />

    <flux:card variant="outline" :highlight="false" class="border-line bg-surface mt-6"
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
                    <flux:text size="sm" variant="subtle">{{ __('Finds your server automatically — no need to know its URL.') }}</flux:text>
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
                        <flux:text>
                            {{ $server['name'] }}
                            @if ($server['machine_identifier'] === $plexSelectedMachineIdentifier)
                                <flux:badge size="sm" color="lime">{{ __('connected') }}</flux:badge>
                            @endif
                        </flux:text>
                        @if ($server['machine_identifier'] !== $plexSelectedMachineIdentifier)
                            <flux:button size="sm" wire:click="selectPlexServer('{{ $server['machine_identifier'] }}')" wire:loading.attr="disabled">{{ __('Use this server') }}</flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    <flux:button variant="ghost" size="sm" class="mt-6" wire:click="$toggle('showAdvanced')">
        {{ $showAdvanced ? __('Hide advanced options') : __('Show advanced options (server URL, token, account)') }}
    </flux:button>

    @if ($showAdvanced)
        <form wire:submit="savePlex" class="mt-4 space-y-6">
            <flux:input wire:model="plexUrl" :label="__('Server URL')" placeholder="http://plex.local:32400" />
            <x-settings.masked-input wire:model="plexToken" :label="__('Token')" :configured="$plexTokenConfigured" />
            <flux:input wire:model="plexAccountId" :label="__('Account ID (optional)')" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <x-settings.test-connection-button :enabled="class_exists(PlexClient::class)" action="testPlex" />
            </div>
        </form>
    @endif

    <flux:button variant="primary" class="mt-8 w-full" wire:click="continueSetup">{{ __('Continue') }}</flux:button>
</x-setup.layout>
