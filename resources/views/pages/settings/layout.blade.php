@php
    $integrationSettings = app(\App\Support\IntegrationSettings::class);
@endphp

<div class="flex items-start gap-6 max-md:flex-col">
    <div class="w-full shrink-0 md:w-64">
        <nav aria-label="{{ __('Settings') }}" class="overflow-hidden rounded-xl border border-line bg-surface p-2">
            <flux:navlist>
                <flux:navlist.item :href="route('profile.edit')" icon="user" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
                <flux:navlist.item :href="route('security.edit')" icon="shield-check" wire:navigate>{{ __('Security') }}</flux:navlist.item>
                <flux:navlist.item :href="route('appearance.edit')" icon="swatch" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
                <flux:navlist.item :href="route('devices.edit')" icon="device-phone-mobile" wire:navigate>{{ __('Devices') }}</flux:navlist.item>
                <flux:navlist.item :href="route('settings.backups')" icon="archive-box" wire:navigate>{{ __('Backups') }}</flux:navlist.item>
                <flux:navlist.item :href="route('settings.updates')" icon="arrow-path" wire:navigate>{{ __('Updates') }}</flux:navlist.item>

                <flux:navlist.group heading="{{ __('Features') }}" class="mt-4">
                    <flux:navlist.item :href="route('settings.features.collection')" icon="rectangle-stack" wire:navigate>
                        {{ __('Collection') }}
                        <x-settings.status-dot :configured="auth()->user()->collection_enabled" />
                    </flux:navlist.item>
                    <flux:navlist.item :href="route('settings.features.trakt')" icon="arrow-up-tray" wire:navigate>
                        {{ __('Trakt import') }}
                    </flux:navlist.item>
                </flux:navlist.group>

                <flux:navlist.group heading="{{ __('Integrations') }}" class="mt-4">
                    <flux:navlist.item :href="route('settings.integrations.plex')" icon="play-circle" wire:navigate>
                        {{ __('Plex') }}
                        <x-settings.status-dot :configured="$integrationSettings->configured('plex.token')" />
                    </flux:navlist.item>
                    <flux:navlist.item :href="route('settings.integrations.seerr')" icon="inbox-arrow-down" wire:navigate>
                        {{ __('Seerr') }}
                        <x-settings.status-dot :configured="$integrationSettings->configured('seerr.api_key')" />
                    </flux:navlist.item>
                    <flux:navlist.item :href="route('settings.integrations.sonarr')" icon="tv" wire:navigate>
                        {{ __('Sonarr') }}
                        <x-settings.status-dot :configured="$integrationSettings->configured('sonarr.api_key')" />
                    </flux:navlist.item>
                    <flux:navlist.item :href="route('settings.integrations.radarr')" icon="film" wire:navigate>
                        {{ __('Radarr') }}
                        <x-settings.status-dot :configured="$integrationSettings->configured('radarr.api_key')" />
                    </flux:navlist.item>
                    <flux:navlist.item :href="route('settings.integrations.qbittorrent')" icon="arrow-down-tray" wire:navigate>
                        {{ __('qBittorrent') }}
                        <x-settings.status-dot :configured="$integrationSettings->configured('qbittorrent.url')" />
                    </flux:navlist.item>
                    <flux:navlist.item :href="route('settings.integrations.pushover')" icon="bell-alert" wire:navigate>
                        {{ __('Pushover') }}
                        <x-settings.status-dot :configured="$integrationSettings->configured('pushover.user_key', 'pushover.app_token')" />
                    </flux:navlist.item>
                    <flux:navlist.item :href="route('settings.integrations.tmdb')" icon="photo" wire:navigate>
                        {{ __('TMDB') }}
                        <x-settings.status-dot :configured="$integrationSettings->configured('tmdb.token') || filled(config('services.tmdb.token'))" />
                    </flux:navlist.item>
                    <flux:navlist.item :href="route('settings.integrations.mdblist')" icon="star" wire:navigate>
                        {{ __('MDBList') }}
                        <x-settings.status-dot :configured="$integrationSettings->configured('mdblist.api_key')" />
                    </flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>
        </nav>
    </div>

    <div class="min-w-0 flex-1 self-stretch">
        <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
            <flux:card.header>
                <flux:card.heading size="lg" class="font-semibold text-ink">{{ $heading ?? '' }}</flux:card.heading>
                <flux:card.subheading>{{ $subheading ?? '' }}</flux:card.subheading>
            </flux:card.header>

            <flux:card.body class="w-full max-w-lg">
                {{ $slot }}
            </flux:card.body>
        </flux:card>
    </div>
</div>
