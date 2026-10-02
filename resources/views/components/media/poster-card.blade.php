@props([
    'image' => null,
    'name',
    'subtitle' => null,
    'href' => null,
    'status' => null,
    'watched' => false,
    'progress' => null,
    'size' => 'md',
    'type' => 'movie',
    'plexAvailable' => false,
])

@php
    $widths = [
        'sm' => 'w-28',
        'md' => 'w-36',
        'lg' => 'w-48',
        'fluid' => 'w-full',
    ];

    $width = $widths[$size] ?? $widths['md'];

    $initials = collect(preg_split('/\s+/', trim((string) $name)))
        ->filter()
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->take(2)
        ->implode('');

    $tag = $href ? 'a' : 'div';

    $statusConfig = match ($status) {
        'available' => ['label' => __('Available'), 'icon' => 'check-circle', 'tone' => 'success'],
        'requested' => ['label' => __('Requested'), 'icon' => 'clock', 'tone' => 'neutral', 'class' => '!bg-status-requested/15 !text-status-requested'],
        'pending' => ['label' => __('Pending'), 'icon' => 'clock', 'tone' => 'warning'],
        'downloading' => ['label' => __('Downloading'), 'icon' => 'arrow-down-tray', 'tone' => 'info'],
        'watched' => ['label' => __('Watched'), 'icon' => 'check-circle', 'tone' => 'accent'],
        'abandoned' => ['label' => __('Abandoned'), 'icon' => 'eye-slash', 'tone' => 'neutral', 'class' => '!bg-status-abandoned/15 !text-status-abandoned'],
        'paused' => ['label' => __('Paused'), 'icon' => 'pause-circle', 'tone' => 'neutral', 'class' => '!bg-status-paused/15 !text-status-paused'],
        default => null,
    };
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class(["group flex shrink-0 snap-start flex-col gap-2 {$width} focus-visible:outline-none"]) }}
>
    <div class="relative aspect-2/3 w-full overflow-hidden rounded-lg bg-surface">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $name }}" loading="lazy" class="size-full object-cover" />
        @else
            <div class="flex size-full items-center justify-center bg-gradient-to-br from-surface-raised to-surface text-2xl font-semibold text-ink-subtle">
                {{ $initials ?: '?' }}
            </div>
        @endif

        @if ($statusConfig || isset($badge) || $plexAvailable)
            <div class="absolute left-1.5 top-1.5 flex flex-col items-start gap-1">
                @if ($statusConfig)
                    <x-media.badge :tone="$statusConfig['tone']" :icon="$statusConfig['icon']" :class="$statusConfig['class'] ?? ''">
                        {{ $statusConfig['label'] }}
                    </x-media.badge>
                @endif

                @if ($plexAvailable)
                    <x-media.badge tone="plex" variant="solid" icon="play" :title="__('Available on Plex')">
                        {{ __('Plex') }}
                    </x-media.badge>
                @endif

                {{ $badge ?? '' }}
            </div>
        @endif

        @if ($watched)
            <div class="absolute right-1.5 top-1.5 flex size-5 items-center justify-center rounded-full bg-status-watched text-accent-foreground">
                <flux:icon.check class="size-3.5" />
            </div>
        @endif

        @if (! is_null($progress))
            <div class="absolute inset-x-0 bottom-0 px-1.5 pb-1.5">
                <x-media.progress :value="$progress" :max="100" />
            </div>
        @endif

        @isset($action)
            <div class="absolute inset-x-0 bottom-0 flex justify-center bg-gradient-to-t from-black/70 to-transparent p-2 pt-6">
                {{ $action }}
            </div>
        @endisset

        <span class="sr-only">{{ $type === 'show' ? __('TV Show') : __('Movie') }}</span>

        <x-media.hover-ring />
    </div>

    <div class="flex flex-col gap-1">
        <span class="truncate text-sm font-medium text-ink">{{ $name }}</span>

        @isset($meta)
            <span class="flex items-center gap-1.5 text-xs text-ink-subtle">{{ $meta }}</span>
        @elseif ($subtitle)
            <span class="truncate text-xs text-ink-subtle">{{ $subtitle }}</span>
        @endisset
    </div>
</{{ $tag }}>
