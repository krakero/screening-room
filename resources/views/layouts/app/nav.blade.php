<!DOCTYPE html>
@php
    $accentValue = auth()->user()?->accent;
    $accent = \App\Support\AccentColor::resolve($accentValue);
@endphp
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="dark"
    data-timezone="{{ \App\Support\DisplayTimezone::current() }}"
    @if ($accent['preset']) data-accent="{{ $accent['preset'] }}" @endif
>
    <head>
        @include('partials.head')

        @include('partials.accent')
    </head>
    <body class="min-h-screen bg-canvas text-ink pb-16 md:pb-0">
        @php($isHero = $fullBleed ?? false)

        <flux:header
            container
            sticky
            class="z-40 border-b border-line bg-canvas/80 backdrop-blur supports-[backdrop-filter]:bg-canvas/60"
        >
            <a href="{{ route('dashboard') }}" wire:navigate class="flex shrink-0 items-center gap-2">
                <span class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
                    <x-app-logo-icon class="size-5 fill-current" />
                </span>
                <span class="hidden font-semibold text-ink sm:inline">{{ config('app.name', 'Screening Room') }}</span>
            </a>

            <flux:navbar class="-mb-px ms-4 max-md:hidden">
                @foreach (\App\Support\PrimaryNavigation::items() as $item)
                    <flux:navbar.item :href="route($item['route'])" :current="request()->routeIs($item['active'])" wire:navigate>
                        {{ $item['label'] }}
                    </flux:navbar.item>
                @endforeach
            </flux:navbar>

            <flux:spacer />

            <form
                action="{{ route('search') }}"
                method="GET"
                class="hidden w-full max-w-sm md:block"
                x-data
                x-on:keydown.window.slash="if (! $event.target.closest('input, textarea, select, [contenteditable]')) { $event.preventDefault(); $refs.searchInput.focus() }"
            >
                <flux:input
                    x-ref="searchInput"
                    type="search"
                    name="q"
                    :value="request('q')"
                    icon="magnifying-glass"
                    :placeholder="__('Search movies & shows…')"
                    kbd="/"
                />
            </form>

            <flux:navbar class="ms-1.5 md:hidden">
                <flux:tooltip :content="__('Search')" position="bottom">
                    <flux:navbar.item icon="magnifying-glass" :href="route('search')" wire:navigate :label="__('Search')" />
                </flux:tooltip>
            </flux:navbar>

            <x-desktop-user-menu class="hidden md:ms-4 md:flex" :name="auth()->user()->name" />

            <flux:dropdown position="bottom" align="end" class="md:hidden">
                <flux:profile :initials="auth()->user()->initials()" data-test="mobile-menu-button" />

                <flux:menu>
                    <x-user-menu.items />
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        @if ($isHero)
            {{-- Full-bleed pages: no flux:main, because its built-in padding would inset the hero. --}}
            <main class="w-full" data-full-bleed>
                {{ $slot }}
            </main>
        @else
            <x-page-container>
                <flux:main>
                    {{ $slot }}
                </flux:main>
            </x-page-container>
        @endif

        @include('partials.mobile-tab-bar')

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        <livewire:episode-flyout />

        @fluxScripts
    </body>
</html>
