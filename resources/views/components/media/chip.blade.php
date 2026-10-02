{{--
    x-media.chip: a neutral metadata chip (year, runtime, genre, source name, episode "Special"
    label, …). A thin wrapper around x-media.badge so radius/case/size stay unified; keep using
    this for plain metadata, and reach for x-media.badge directly for anything with real status
    semantics (icon, Plex, "Up Next", …).
--}}
@props([
    'tone' => null, // null|available|pending|muted|danger
])

@php
    $badgeTone = match ($tone) {
        'available' => 'success',
        'pending' => 'warning',
        'muted' => 'neutral',
        'danger' => 'danger',
        default => 'neutral',
    };
@endphp

<x-media.badge :tone="$badgeTone" variant="soft" {{ $attributes }}>
    {{ $slot }}
</x-media.badge>
