@props([
    'backdrop' => null,
    'poster' => null,
])

<div {{ $attributes->class(['relative w-full']) }}>
    <div class="relative aspect-[8/3] w-full overflow-hidden bg-surface md:aspect-[7/2]">
        @if ($backdrop)
            <img src="{{ $backdrop }}" alt="" class="size-full object-cover object-[50%_25%]" />
        @else
            <div class="size-full bg-gradient-to-br from-surface-raised to-surface"></div>
        @endif

        <div class="absolute inset-0 bg-gradient-to-t from-canvas via-canvas/60 to-transparent"></div>
        <div class="absolute inset-0 bg-gradient-to-r from-canvas/80 via-transparent to-transparent"></div>
    </div>

    <x-page-container class="relative -mt-16 flex flex-col gap-4 pb-6 md:-mt-20 md:flex-row md:items-end">
        @if ($poster)
            <img
                src="{{ $poster }}"
                alt=""
                class="hidden aspect-2/3 w-40 shrink-0 rounded-lg object-cover shadow-xl ring-1 ring-line md:block lg:w-48"
            />
        @endif

        <div class="flex flex-1 flex-col gap-3">
            @isset($title)
                <div class="text-3xl font-bold text-ink sm:text-4xl">{{ $title }}</div>
            @endisset

            @isset($meta)
                <div class="flex flex-wrap items-center gap-2 text-sm text-ink-muted">{{ $meta }}</div>
            @endisset

            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    </x-page-container>
</div>
