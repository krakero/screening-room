@props([
    'item',
])

<x-media.poster-card
    {{ $attributes }}
    :image="$item['poster']"
    :name="$item['name']"
    :href="$item['href']"
    :watched="$item['watched']"
    :status="$item['status']"
    :type="$item['type']"
>
    @if ($item['on_list'] || $item['followed'] || ($item['is_re_release'] ?? false))
        <x-slot:badge>
            @if ($item['on_list'])
                <x-media.badge tone="accent" icon="bookmark">{{ __('On list') }}</x-media.badge>
            @endif

            @if ($item['followed'])
                <x-media.badge tone="accent" icon="eye">{{ __('Following') }}</x-media.badge>
            @endif

            @if ($item['is_re_release'] ?? false)
                <x-media.badge tone="accent" icon="arrow-path">{{ __('Re-release') }}</x-media.badge>
            @endif
        </x-slot:badge>
    @endif

    <x-slot:meta>
        <x-media.chip>
            {{ $item['type'] === 'movie' ? __('Movie') : __('TV Show') }}
        </x-media.chip>
        @if ($item['year'])
            {{ $item['year'] }}
        @endif
    </x-slot:meta>
</x-media.poster-card>
