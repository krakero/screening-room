@props([
    'value' => null,
    'action',
    'size' => 'md',
])

@php
    $sizeClasses = match ($size) {
        'sm' => 'size-5',
        'lg' => 'size-8',
        default => 'size-6',
    };
    $committed = (int) ($value ?? 0);
@endphp

<div
    {{ $attributes->class(['inline-flex items-center gap-2']) }}
    wire:key="star-rating-{{ $action }}-{{ $committed }}"
    x-data="optimistic({{ $committed }})"
>
    <div
        role="radiogroup"
        class="flex items-center"
        x-data="{
            hover: null,
            display() { return this.hover ?? value },
            fill(star) {
                const v = this.display();
                if (v >= star * 2) return 100;
                if (v >= star * 2 - 1) return 50;
                return 0;
            },
        }"
        :aria-label="`Rate ${(display() / 2).toFixed(1)} out of 5`"
        @keydown.arrow-right.prevent="let n = Math.min(10, value + 1); set(n, () => $wire.{{ $action }}(n))"
        @keydown.arrow-left.prevent="let n = Math.max(1, value - 1); set(n, () => $wire.{{ $action }}(n))"
    >
        @for ($star = 1; $star <= 5; $star++)
            @php
                $leftValue = $star * 2 - 1;
                $rightValue = $star * 2;
            @endphp

            <span class="relative inline-block {{ $sizeClasses }}" @mouseleave="hover = null">
                <flux:icon.star class="{{ $sizeClasses }} text-line" variant="outline" />

                <span class="pointer-events-none absolute inset-0 overflow-hidden" :style="`width: ${fill({{ $star }})}%`">
                    <flux:icon.star class="{{ $sizeClasses }} text-accent" variant="solid" />
                </span>

                <button
                    type="button"
                    role="radio"
                    x-ref="star-{{ $leftValue }}"
                    :aria-checked="value === {{ $leftValue }}"
                    aria-label="{{ __('Rate :n out of 5', ['n' => number_format($leftValue / 2, 1)]) }}"
                    tabindex="{{ $leftValue === max($committed, 1) ? 0 : -1 }}"
                    class="absolute inset-y-0 left-0 w-1/2 cursor-pointer"
                    @mouseenter="hover = {{ $leftValue }}"
                    @click="let n = (value === {{ $leftValue }} ? 0 : {{ $leftValue }}); set(n, () => $wire.{{ $action }}(n))"
                ></button>

                <button
                    type="button"
                    role="radio"
                    x-ref="star-{{ $rightValue }}"
                    :aria-checked="value === {{ $rightValue }}"
                    aria-label="{{ __('Rate :n out of 5', ['n' => $star]) }}"
                    tabindex="{{ $rightValue === max($committed, 1) ? 0 : -1 }}"
                    class="absolute inset-y-0 right-0 w-1/2 cursor-pointer"
                    @mouseenter="hover = {{ $rightValue }}"
                    @click="let n = (value === {{ $rightValue }} ? 0 : {{ $rightValue }}); set(n, () => $wire.{{ $action }}(n))"
                ></button>
            </span>
        @endfor
    </div>

    <span class="text-sm font-medium text-ink tabular-nums" x-text="value ? (value / 2).toFixed(1) : '—'"></span>
</div>
