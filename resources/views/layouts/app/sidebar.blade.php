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
    <body class="min-h-screen bg-canvas md:bg-surface text-ink pb-16 md:pb-0">
        @php($isHero = $fullBleed ?? false)

        {{-- <flux:sidebar>, the mobile header and <flux:main> must be direct, adjacent children of
            <body> in this order: Flux's CSS turns body into the sidebar/header/main grid via
            `*:has(>[data-flux-main])` and `*:has(>[data-flux-sidebar]+[data-flux-header])` (see
            vendor/livewire/flux/dist/flux.css). Wrapping any of them in a div breaks the grid. --}}
        <flux:sidebar sticky collapsible class="max-md:hidden bg-surface">
            <flux:sidebar.header>
                <x-app-logo sidebar href="{{ route('dashboard') }}" wire:navigate />

                <flux:sidebar.collapse />
            </flux:sidebar.header>

            <form
                action="{{ route('search') }}"
                method="GET"
                class="in-data-flux-sidebar-collapsed-desktop:hidden"
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

            <flux:sidebar.nav>
                @foreach (\App\Support\PrimaryNavigation::items() as $item)
                    <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs($item['active'])" wire:navigate>
                        {{ $item['label'] }}
                    </flux:sidebar.item>
                @endforeach
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:dropdown position="top" align="start" class="w-full">
                <flux:sidebar.profile :name="auth()->user()->name" :initials="auth()->user()->initials()" data-test="user-menu-button" />

                <flux:menu>
                    <x-user-menu.items />
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <flux:header
            sticky
            class="md:hidden z-40 border-b border-line bg-canvas/80 backdrop-blur supports-[backdrop-filter]:bg-canvas/60"
        >
            <a href="{{ route('dashboard') }}" wire:navigate class="flex shrink-0 items-center gap-2">
                <span class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
                    <x-app-logo-icon class="size-5 fill-current" />
                </span>
                <span class="hidden font-semibold text-ink sm:inline">{{ config('app.name', 'Screening Room') }}</span>
            </a>

            <flux:spacer />

            <flux:navbar class="ms-1.5">
                <flux:tooltip :content="__('Search')" position="bottom">
                    <flux:navbar.item icon="magnifying-glass" :href="route('search')" wire:navigate :label="__('Search')" />
                </flux:tooltip>
            </flux:navbar>

            <flux:dropdown position="bottom" align="end">
                <flux:profile :initials="auth()->user()->initials()" data-test="mobile-menu-button" />

                <flux:menu>
                    <x-user-menu.items />
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        @if ($isHero)
            {{-- Full-bleed pages: still use flux:main (so the sidebar/header/main grid and the inset
                card shell still apply), but zero its padding so the hero fills the card edge to
                edge; the card's rounded corners (lg+) clip it. --}}
            <flux:main
                inset
                class="p-0! bg-canvas border-line"
                data-full-bleed
                x-data
                x-on:livewire:navigated.window="$el.scrollTop = 0"
            >
                {{ $slot }}
            </flux:main>
        @else
            <flux:main
                inset
                class="px-0! bg-canvas border-line"
                x-data
                x-on:livewire:navigated.window="$el.scrollTop = 0"
            >
                <x-page-container>
                    {{ $slot }}
                </x-page-container>
            </flux:main>
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
