<div>
    @if ($available)
        <div x-data x-on:plex-auth-opened.window="window.open($event.detail.url, '_blank')" wire:poll.2s="poll">
            <flux:checkbox wire:model="remember" :label="__('Remember me')" class="mb-4" />

            <button
                type="button"
                wire:click="startSignIn"
                wire:loading.attr="disabled"
                wire:target="startSignIn"
                :disabled="$polling"
                data-test="plex-login-button"
                class="inline-flex h-12 w-full shrink-0 items-center justify-center gap-2 rounded-lg bg-plex px-4 text-base font-semibold text-plex-foreground shadow-xs transition hover:bg-[color-mix(in_oklab,var(--color-plex),black_10%)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-plex focus-visible:ring-offset-2 focus-visible:ring-offset-canvas disabled:cursor-not-allowed disabled:opacity-60"
            >
                @if ($polling)
                    <flux:icon.loading variant="micro" />
                    {{ __('Waiting for sign-in…') }}
                @else
                    <svg viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" aria-hidden="true">
                        <path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-1.5 5 5 4-5 4V8Z" fill="currentColor" />
                    </svg>
                    {{ __('Sign in with Plex') }}
                @endif
            </button>

            @error('plex')
                <flux:text size="sm" class="mt-3 text-red-500">{{ $message }}</flux:text>
            @enderror

            @if ($polling && $authUrl !== '')
                <flux:text size="sm" variant="subtle" class="mt-3">
                    {{ __('A tab should have opened at app.plex.tv. If it didn\'t,') }}
                    <flux:link href="{{ $authUrl }}" target="_blank" rel="noopener">{{ __('open it manually') }}</flux:link>.
                </flux:text>
            @endif
        </div>
    @endif
</div>
