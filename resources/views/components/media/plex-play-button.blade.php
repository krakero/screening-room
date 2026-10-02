@props([
    'url',
    'label' => null,
    'size' => null,
    'iconOnly' => false,
    'branded' => false,
])

@php
    $label ??= __('Watch on Plex');
@endphp

@if ($url)
    @if ($branded)
        {{--
            Plex-branded: solid Plex-yellow pill with the chevron mark + "Watch on Plex" text.
            A plain anchor (not flux:button) so it can carry the app's `bg-plex`/`text-plex-foreground`
            tokens directly — Flux's `color` prop only maps known Tailwind palette names, not the
            app's custom `plex` token. Sizing/shape mirrors flux:button's `sm` size for parity with
            the flyout's other action-row buttons.
        --}}
        <a
            {{ $attributes->class(['inline-flex h-8 shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-md bg-plex px-3 text-sm font-medium text-plex-foreground shadow-xs transition hover:bg-[color-mix(in_oklab,var(--color-plex),black_10%)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-plex focus-visible:ring-offset-2 focus-visible:ring-offset-canvas']) }}
            href="{{ $url }}"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="{{ $label }}"
        >
            <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" aria-hidden="true">
                <path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-1.5 5 5 4-5 4V8Z" fill="currentColor" />
            </svg>

            {{ $label }}
        </a>
    @elseif ($iconOnly)
        <flux:button
            {{ $attributes }}
            :size="$size"
            variant="ghost"
            icon="play"
            :href="$url"
            target="_blank"
            rel="noopener noreferrer"
            :aria-label="$label"
        />
    @else
        <flux:button
            {{ $attributes }}
            :size="$size"
            variant="primary"
            icon="play"
            :href="$url"
            target="_blank"
            rel="noopener noreferrer"
        >
            {{ $label }}
        </flux:button>
    @endif
@endif
