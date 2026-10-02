@props([
    'name' => 'watch-trailer',
    'open' => false,
    'embedUrl' => null,
    'watchUrl' => null,
    'site' => null,
    'onClose' => 'closeTrailer',
])

<flux:modal :name="$name" class="w-[calc(100vw-2rem)] max-w-none" @close="{{ $onClose }}">
    <div class="flex flex-col items-center gap-4">
        <flux:heading size="lg" class="self-start">{{ __('Trailer') }}</flux:heading>

        @if ($open && $embedUrl)
            {{-- Largest 16:9 box that fits both the viewport width and height; shrink-0 keeps the
                 dialog's flex column from squashing it vertically. --}}
            <div class="aspect-video w-[min(100%,calc((100dvh-12rem)*16/9))] shrink-0 overflow-hidden rounded-lg bg-surface-raised">
                <iframe
                    src="{{ $embedUrl }}"
                    class="size-full"
                    allow="autoplay; encrypted-media; picture-in-picture"
                    allowfullscreen
                ></iframe>
            </div>
        @endif

        <div class="flex justify-end self-stretch">
            <flux:button :href="$watchUrl" target="_blank" rel="noopener noreferrer" variant="ghost" icon:trailing="arrow-top-right-on-square">
                {{ __('Open on :site', ['site' => $site]) }}
            </flux:button>
        </div>
    </div>
</flux:modal>
