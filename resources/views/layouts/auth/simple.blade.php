<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <div class="relative flex min-h-svh flex-col items-center justify-center overflow-hidden p-6 md:p-10">
            <div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
                <div class="absolute -top-32 -left-24 h-96 w-96 rounded-full bg-accent/25 blur-[120px]"></div>
                <div class="absolute top-10 -right-24 h-80 w-80 rounded-full bg-status-requested/20 blur-[120px]"></div>
                <div class="absolute bottom-0 left-1/4 h-96 w-96 rounded-full bg-status-available/15 blur-[130px]"></div>
                <div class="absolute -right-16 -bottom-32 h-80 w-80 rounded-full bg-status-downloading/15 blur-[120px]"></div>
                <div class="absolute inset-0 bg-canvas/70"></div>
            </div>

            <div class="flex w-full max-w-sm flex-col gap-3">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="mb-1 flex h-11 w-11 items-center justify-center rounded-xl bg-accent-content text-accent-foreground shadow-lg shadow-black/30">
                        <x-app-logo-icon class="size-6 fill-current" />
                    </span>
                    <span class="text-sm font-semibold tracking-wide text-ink-muted uppercase">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="flex flex-col gap-6 rounded-xl border border-line bg-surface/70 p-8 shadow-2xl shadow-black/40 backdrop-blur-xl">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
