@props([
    'heading' => null,
    'href' => null,
    'subheading' => null,
])

<section
    {{ $attributes->class(['group/row flex flex-col gap-3']) }}
    x-data="{
        canScrollLeft: false,
        canScrollRight: false,
        updateFades() {
            const track = $refs.track;
            this.canScrollLeft = track.scrollLeft > 0;
            this.canScrollRight = track.scrollLeft + track.clientWidth < track.scrollWidth - 1;
        },
    }"
    x-init="updateFades(); $nextTick(() => updateFades())"
    @resize.window="updateFades()"
>
    @if ($heading)
        <x-media.section-header :heading="$heading" :subheading="$subheading">
            @if ($href)
                <x-slot:actions>
                    <a href="{{ $href }}" wire:navigate class="text-sm font-medium text-accent hover:underline">
                        {{ __('See all') }}
                    </a>
                </x-slot:actions>
            @endif
        </x-media.section-header>
    @endif

    <div class="relative">
        <div
            x-ref="track"
            @scroll="updateFades()"
            class="flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
            {{ $slot }}
        </div>

        <div x-show="canScrollLeft" class="pointer-events-none absolute inset-y-0 left-0 hidden w-10 bg-gradient-to-r from-canvas to-transparent md:block"></div>
        <div x-show="canScrollRight" class="pointer-events-none absolute inset-y-0 right-0 hidden w-10 bg-gradient-to-l from-canvas to-transparent md:block"></div>

        <button
            type="button"
            x-show="canScrollLeft"
            :disabled="!canScrollLeft"
            @click="$refs.track.scrollBy({ left: -320, behavior: 'smooth' })"
            aria-label="{{ __('Scroll left') }}"
            class="absolute left-1 top-1/2 hidden size-8 -translate-y-1/2 items-center justify-center rounded-full bg-surface/90 text-ink opacity-0 ring-1 ring-line transition group-hover/row:opacity-100 focus-visible:opacity-100 md:flex"
        >
            <flux:icon.chevron-left class="size-4" />
        </button>

        <button
            type="button"
            x-show="canScrollRight"
            :disabled="!canScrollRight"
            @click="$refs.track.scrollBy({ left: 320, behavior: 'smooth' })"
            aria-label="{{ __('Scroll right') }}"
            class="absolute right-1 top-1/2 hidden size-8 -translate-y-1/2 items-center justify-center rounded-full bg-surface/90 text-ink opacity-0 ring-1 ring-line transition group-hover/row:opacity-100 focus-visible:opacity-100 md:flex"
        >
            <flux:icon.chevron-right class="size-4" />
        </button>
    </div>
</section>
