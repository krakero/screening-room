{{--
    x-titles.watch-providers: "Where to watch" logos for the current region, grouped Stream / Free,
    with the (required) JustWatch credit. Renders nothing until providers have been checked, or
    when the title has none.
--}}
@props([
    'title',
])

@php
    $providers = $title->watch_providers_checked_at !== null && app(\App\Services\Tmdb\TmdbClient::class)->showWatchProviders() ? $title->streamingProviders() : collect();

    $groups = [
        __('Stream') => $providers->filter(fn ($provider) => $provider->pivot->type === \App\Enums\WatchProviderType::Flatrate),
        __('Free') => $providers->filter(fn ($provider) => $provider->pivot->type !== \App\Enums\WatchProviderType::Flatrate),
    ];
@endphp

@if ($providers->isNotEmpty())
    <div {{ $attributes->class(['flex flex-col gap-2']) }} data-watch-providers>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ __('Where to watch') }}</span>

            @foreach ($groups as $label => $group)
                @if ($group->isNotEmpty())
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-ink-muted">{{ $label }}</span>
                        @foreach ($group as $provider)
                            <flux:tooltip :content="$provider->name">
                                @if ($provider->logoUrl())
                                    <img src="{{ $provider->logoUrl() }}" alt="{{ $provider->name }}" loading="lazy" class="size-8 rounded-md object-cover">
                                @else
                                    <x-media.chip>{{ $provider->name }}</x-media.chip>
                                @endif
                            </flux:tooltip>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>

        <a href="https://www.justwatch.com" target="_blank" rel="noopener noreferrer" class="text-xs text-ink-subtle hover:text-ink-muted">
            {{ __('Streaming data by JustWatch') }}
        </a>
    </div>
@endif
