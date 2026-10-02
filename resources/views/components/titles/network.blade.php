{{--
    x-titles.network: a show's primary network as branding above the title, e.g. the Apple TV
    logo over "Ted Lasso". TMDB has one logo per network (usually dark-on-transparent, sometimes
    colored or white), so at rest it's a muted single-color silhouette that follows the theme
    (black in light mode, white in dark mode, at 45%), brightening to full strength on hover. The
    name appears in a small tooltip after a one-second hover; flux:tooltip has no delay option,
    so this uses a local Alpine timer. Falls back to the name in small caps when TMDB has no logo.
    Shown regardless of the "Show where to watch" setting: the network is where the show airs,
    not streaming availability.
--}}
@props([
    'title',
])

@php
    $network = $title->isShow() ? $title->networks->first() : null;
@endphp

@if ($network && filled($network->name))
    @if (filled($network->logo_path))
        <div
            x-data="{ open: false, timer: null }"
            x-on:mouseenter="timer = setTimeout(() => open = true, 1000)"
            x-on:mouseleave="clearTimeout(timer); open = false"
            {{ $attributes->class(['group/network relative self-start']) }}
        >
            <img
                src="{{ $network->logoUrl() }}"
                alt="{{ $network->name }}"
                loading="lazy"
                class="h-4 w-auto max-w-20 object-contain opacity-45 brightness-0 transition-opacity duration-200 group-hover/network:opacity-100 dark:invert"
            >

            <span
                x-show="open"
                x-cloak
                x-transition.opacity.duration.150ms
                role="tooltip"
                class="absolute left-0 top-full z-50 mt-1.5 whitespace-nowrap rounded-md bg-ink px-2 py-1 text-xs font-medium text-canvas shadow-md"
            >{{ $network->name }}</span>
        </div>
    @else
        <span {{ $attributes->class(['text-xs font-semibold uppercase tracking-wider text-ink-muted']) }}>{{ $network->name }}</span>
    @endif
@endif
