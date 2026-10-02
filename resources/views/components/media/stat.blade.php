@props([
    'label',
    'value',
])

<div {{ $attributes->class(['flex flex-col gap-1 rounded-xl bg-surface p-4 ring-1 ring-line']) }}>
    <span class="text-2xl font-semibold text-ink">{{ $value }}</span>
    <span class="text-xs uppercase tracking-wide text-ink-subtle">{{ $label }}</span>
</div>
