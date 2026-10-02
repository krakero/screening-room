@props([
    'value' => 0,
    'max' => 100,
    'label' => null,
])

@php
    $max = max((int) $max, 1);
    $percentage = max(0, min(100, ((float) $value / $max) * 100));
@endphp

<div {{ $attributes->class(['flex flex-col gap-1']) }}>
    <div class="h-1 w-full overflow-hidden rounded-full bg-line">
        <div class="h-full rounded-full bg-accent" style="width: {{ $percentage }}%"></div>
    </div>

    @if ($label)
        <span class="text-xs text-ink-subtle">{{ $label }}</span>
    @endif
</div>
