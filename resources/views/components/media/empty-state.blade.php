@props([
    'icon' => null,
    'heading',
])

<div {{ $attributes->class(['flex flex-col items-center justify-center gap-3 rounded-xl border border-line p-12 text-center']) }}>
    @if ($icon)
        <flux:icon :icon="$icon" class="size-8 text-ink-subtle" />
    @endif

    <flux:heading size="lg">{{ $heading }}</flux:heading>

    @if ($slot->isNotEmpty())
        <div class="max-w-sm text-sm text-ink-muted">{{ $slot }}</div>
    @endif
</div>
