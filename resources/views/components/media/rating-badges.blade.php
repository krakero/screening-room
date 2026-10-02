@props([
    'ratings' => [],
])

<div {{ $attributes->class(['flex flex-wrap items-center gap-2']) }}>
    @foreach ($ratings as $rating)
        <x-media.chip>
            <span class="font-semibold text-ink">{{ $rating->source->label() }}</span>
            {{ ' ' }}{{ $rating->source->format($rating->value) }}<span class="text-ink-subtle">/{{ (int) $rating->max }}</span>
        </x-media.chip>
    @endforeach
</div>
