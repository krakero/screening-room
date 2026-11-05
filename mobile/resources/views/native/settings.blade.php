<native:refreshable class="w-full h-full bg-theme-background" @refresh="fetchData">
    @if ($loading)
        <native:column class="w-full h-full items-center justify-center">
            <native:activity-indicator />
        </native:column>
    @elseif ($error)
        <native:column class="w-full h-full items-center justify-center gap-4 px-4">
            <native:text class="text-base text-center text-theme-on-background">{{ $error }}</native:text>
            <native:button label="Retry" @press="fetchData" />
        </native:column>
    @else
        <native:column class="w-full gap-6 p-4">
            {{-- Account Section --}}
            @if ($user)
                <native:column class="gap-2">
                    <native:text class="text-xs font-semibold uppercase tracking-wide text-theme-on-surface-variant px-2">Account</native:text>
                    <native:column class="bg-theme-surface rounded-xl p-4 gap-3">
                        <native:column class="gap-1">
                            <native:text class="text-base font-semibold text-theme-on-surface">{{ $user['name'] }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $user['email'] }}</native:text>
                        </native:column>
                        <native:divider />
                        <native:row class="justify-between items-center">
                            <native:text class="text-base text-theme-on-surface">Timezone</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $user['timezone'] }}</native:text>
                        </native:row>
                    </native:column>
                </native:column>
            @endif

            {{-- Stats Section --}}
            @if ($stats)
                <native:column class="gap-2">
                    <native:text class="text-xs font-semibold uppercase tracking-wide text-theme-on-surface-variant px-2">Your Stats</native:text>
                    <native:column class="bg-theme-surface rounded-xl p-4 gap-3">
                        <native:row class="justify-between items-center">
                            <native:text class="text-base text-theme-on-surface">Episodes Watched</native:text>
                            <native:text class="text-base font-semibold text-theme-primary">{{ number_format($stats['episodesWatched']) }}</native:text>
                        </native:row>
                        <native:divider />
                        <native:row class="justify-between items-center">
                            <native:text class="text-base text-theme-on-surface">Movies Watched</native:text>
                            <native:text class="text-base font-semibold text-theme-primary">{{ number_format($stats['moviesWatched']) }}</native:text>
                        </native:row>
                        <native:divider />
                        <native:row class="justify-between items-center">
                            <native:text class="text-base text-theme-on-surface">Shows Followed</native:text>
                            <native:text class="text-base font-semibold text-theme-primary">{{ number_format($stats['showsFollowed']) }}</native:text>
                        </native:row>
                        <native:divider />
                        <native:row class="justify-between items-center">
                            <native:text class="text-base text-theme-on-surface">Hours Watched</native:text>
                            <native:text class="text-base font-semibold text-theme-primary">{{ number_format(round($stats['totalWatchMinutes'] / 60)) }}</native:text>
                        </native:row>
                    </native:column>
                </native:column>
            @endif

            {{-- Server Section --}}
            <native:column class="gap-2">
                <native:text class="text-xs font-semibold uppercase tracking-wide text-theme-on-surface-variant px-2">Server</native:text>
                <native:column class="bg-theme-surface rounded-xl p-4 gap-3">
                    <native:column class="gap-1">
                        <native:text class="text-sm text-theme-on-surface-variant">Connected to</native:text>
                        <native:text class="text-base text-theme-on-surface">{{ $serverUrl }}</native:text>
                    </native:column>
                    @if ($integrations)
                        <native:divider />
                        <native:column class="gap-2">
                            <native:text class="text-sm font-medium text-theme-on-surface">Integrations</native:text>
                            <native:column class="gap-2">
                                @foreach ($integrations as $name => $config)
                                    <native:row class="justify-between items-center">
                                        <native:text class="text-base text-theme-on-surface">{{ ucfirst($name) }}</native:text>
                                        @if ($config['configured'])
                                            <native:badge label="Connected" class="bg-theme-primary text-theme-on-primary" />
                                        @else
                                            <native:text class="text-sm text-theme-on-surface-variant">Not configured</native:text>
                                        @endif
                                    </native:row>
                                @endforeach
                            </native:column>
                        </native:column>
                    @endif
                </native:column>
            </native:column>

            {{-- About Section --}}
            <native:column class="gap-2">
                <native:text class="text-xs font-semibold uppercase tracking-wide text-theme-on-surface-variant px-2">About</native:text>
                <native:column class="bg-theme-surface rounded-xl p-4 gap-3">
                    <native:row class="justify-between items-center">
                        <native:text class="text-base text-theme-on-surface">Version</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ config('app.version', '1.0.0') }}</native:text>
                    </native:row>
                    <native:divider />
                    <native:text class="text-base text-center text-theme-on-surface-variant">Made for Screening Room</native:text>
                </native:column>
            </native:column>

            {{-- Sign Out Section --}}
            <native:column class="gap-2 pb-8">
                <native:text class="text-xs font-semibold uppercase tracking-wide text-theme-on-surface-variant px-2">Account Actions</native:text>
                @if ($showSignOutConfirm)
                    <native:column class="bg-theme-surface rounded-xl p-4 gap-3">
                        <native:text class="text-base text-center text-theme-on-surface">Are you sure you want to sign out?</native:text>
                        <native:row class="gap-3">
                            <native:button label="Cancel" @press="cancelSignOut" class="flex-1" />
                            <native:button label="Sign Out" @press="signOut" variant="destructive" class="flex-1" />
                        </native:row>
                    </native:column>
                @else
                    <native:column class="bg-theme-surface rounded-xl">
                        <native:pressable @press="confirmSignOut" class="p-4">
                            <native:text class="text-base text-center text-red-600">Sign Out</native:text>
                        </native:pressable>
                    </native:column>
                @endif
            </native:column>
        </native:column>
    @endif
</native:refreshable>
