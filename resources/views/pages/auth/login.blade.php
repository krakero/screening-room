<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        @if ($hasLinkedPlexUser)
            <livewire:plex-login-button />

            @php
                $expandOtherOptions = $errors->any() || old('email') !== null || request()->boolean('email');
            @endphp

            <details class="group"{{ $expandOtherOptions ? ' open' : '' }}>
                <summary class="flex cursor-pointer list-none items-center justify-center gap-2 text-sm font-medium text-ink-muted transition hover:text-ink">
                    <flux:icon.chevron-right class="size-4 transition group-open:rotate-90" />
                    {{ __('Other options') }}
                </summary>

                <div class="mt-6">
                    @include('pages::auth.partials.email-login-form')
                </div>
            </details>
        @else
            @include('pages::auth.partials.email-login-form')
        @endif
    </div>
</x-layouts::auth>
