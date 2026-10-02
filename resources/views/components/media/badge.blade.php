{{--
    x-media.badge: the one badge/pill/chip component for every status, category, and label
    indicator in the app (status badges, "Up Next", Special, Following, Plex, list membership,
    show status, genre/meta chips, …).

    Props:
    - tone: neutral (default) | accent | plex | success | warning | danger | info
    - variant: soft (default — tinted background, for badges sitting on a surface/panel) |
      solid (opaque background, for badges overlaid on an image/still so they stay legible)
    - size: sm (default) | md
    - icon: optional Heroicon name, rendered before the label

    Always `rounded-full`, sentence case (never uppercase), same font weight/tracking and
    padding scale per size across every tone and variant. A status this file doesn't have a
    dedicated tone for (e.g. "requested", "paused") can reuse its design token by overriding
    the tone's classes with `!important`-prefixed utilities, e.g.
    `<x-media.badge class="!bg-status-requested/15 !text-status-requested !ring-status-requested/30">`.
--}}
@props([
    'tone' => 'neutral',
    'variant' => 'soft',
    'size' => 'sm',
    'icon' => null,
])

@php
    $toneClasses = match ("{$tone}.{$variant}") {
        'neutral.soft' => 'bg-surface-raised text-ink-muted ring-1 ring-line',
        'neutral.solid' => 'bg-canvas/80 text-ink-muted ring-1 ring-line backdrop-blur-sm',
        'accent.soft' => 'bg-accent/15 text-accent ring-1 ring-accent/30',
        'accent.solid' => 'bg-accent text-accent-foreground',
        'plex.soft' => 'bg-plex/15 text-plex ring-1 ring-plex/30',
        'plex.solid' => 'bg-plex text-plex-foreground',
        'success.soft' => 'bg-status-available/15 text-status-available ring-1 ring-status-available/30',
        'success.solid' => 'bg-status-available text-white',
        'warning.soft' => 'bg-status-pending/15 text-status-pending ring-1 ring-status-pending/30',
        'warning.solid' => 'bg-status-pending text-white',
        'danger.soft' => 'bg-red-500/15 text-red-500 ring-1 ring-red-500/30 dark:text-red-400',
        'danger.solid' => 'bg-red-500 text-white',
        'info.soft' => 'bg-status-downloading/15 text-status-downloading ring-1 ring-status-downloading/30',
        'info.solid' => 'bg-status-downloading text-white',
        default => 'bg-surface-raised text-ink-muted ring-1 ring-line',
    };

    $sizeClasses = match ($size) {
        'md' => 'gap-1.5 px-2.5 py-1 text-xs',
        default => 'gap-1 px-2 py-0.5 text-xs',
    };

    $iconSize = match ($size) {
        'md' => 'size-3.5',
        default => 'size-3',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full font-medium', $sizeClasses, $toneClasses]) }}>
    @if ($icon)
        <flux:icon :icon="$icon" class="{{ $iconSize }}" />
    @endif

    {{ $slot }}
</span>
