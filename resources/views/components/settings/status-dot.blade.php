@props([
    'configured' => false,
])

<span
    {{ $attributes->class(['inline-block size-1.5 shrink-0 rounded-full', $configured ? 'bg-status-available' : 'bg-ink-subtle/60']) }}
    aria-hidden="true"
></span>
