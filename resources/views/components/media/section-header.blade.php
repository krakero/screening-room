@props([
    'heading',
    'subheading' => null,
])

<div {{ $attributes->class(['flex items-end justify-between gap-4']) }}>
    <div class="flex flex-col gap-0.5">
        <flux:heading size="lg">{{ $heading }}</flux:heading>

        @if ($subheading)
            <flux:subheading>{{ $subheading }}</flux:subheading>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
