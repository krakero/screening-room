<native:column class="safe-area w-full h-full p-6 gap-6 bg-theme-background">
    <native:column class="gap-2">
        <native:text class="text-3xl font-bold text-theme-on-background">Welcome</native:text>
        <native:text class="text-theme-on-surface-variant">Sign in to your Screening Room server</native:text>
    </native:column>

    <native:column class="gap-4 flex-1">
        <native:outlined-text-input
            native:model="serverUrl"
            label="Server URL"
            placeholder="https://screening.example.com"
            keyboard="url"
            class="w-full"
        />

        @if($waitingForPlex)
            <native:column class="gap-4 items-center justify-center flex-1">
                <native:activity-indicator size="lg" />
                <native:text class="text-theme-on-surface-variant">Waiting for Plex…</native:text>
                <native:text class="text-sm text-center text-theme-on-surface-variant">Log in on the Plex page, then tap Done to come back.</native:text>
                <native:button
                    label="Cancel"
                    variant="ghost"
                    @press="cancelPlexSignIn"
                    class="mt-2"
                />
            </native:column>
        @else
            @if($error)
                <native:column class="p-3 bg-theme-destructive/10 rounded-lg">
                    <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
                </native:column>
            @endif

            <native:button
                label="Sign in with Plex"
                variant="primary"
                @press="signInWithPlex"
                class="mt-2"
            />

            <native:pressable @press="toggleEmailFallback" class="self-center mt-2">
                <native:text class="text-sm text-theme-on-surface-variant">Use email instead</native:text>
            </native:pressable>

            @if($showEmailFallback)
                <native:outlined-text-input
                    native:model="email"
                    label="Email"
                    placeholder="you@example.com"
                    keyboard="email"
                    class="w-full mt-4"
                />

                <native:outlined-text-input
                    native:model="password"
                    label="Password"
                    placeholder="Password"
                    secure
                    revealable
                    class="w-full"
                />

                <native:button
                    label="{{ $loading ? 'Signing in...' : 'Sign In' }}"
                    @press="signIn"
                    disabled="{{ $loading }}"
                    class="mt-2"
                />
            @endif
        @endif
    </native:column>
</native:column>
