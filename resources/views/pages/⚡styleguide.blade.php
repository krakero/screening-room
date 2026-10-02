<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Styleguide')] class extends Component
{
    /** @var array<int, string> */
    public array $statuses = [
        'available',
        'requested',
        'pending',
        'downloading',
        'watched',
        'abandoned',
        'paused',
    ];

    /** @var array<int, string> */
    public array $badgeTones = ['neutral', 'accent', 'plex', 'success', 'warning', 'danger', 'info'];

    /** @var array<int, string> */
    public array $badgeVariants = ['soft', 'solid'];

    /** @var array<int, string> */
    public array $badgeSizes = ['sm', 'md'];

    /** @var array<int, array{name: string, subtitle: string, image: string|null, status: string|null, watched: bool, progress: int|null, type: string}> */
    public array $posters = [
        ['name' => 'Fight Club', 'subtitle' => '1999 · Movie', 'image' => 'https://image.tmdb.org/t/p/w342/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg', 'status' => 'available', 'watched' => true, 'progress' => null, 'type' => 'movie'],
        ['name' => 'Severance', 'subtitle' => '2022 · Show', 'image' => 'https://image.tmdb.org/t/p/w342/lpalCsSK4YjSk92hbnkFsyBGpDA.jpg', 'status' => 'downloading', 'watched' => false, 'progress' => 62, 'type' => 'show'],
        ['name' => 'The Bear', 'subtitle' => '2022 · Show', 'image' => null, 'status' => 'requested', 'watched' => false, 'progress' => null, 'type' => 'show'],
        ['name' => 'Dune: Part Two', 'subtitle' => '2024 · Movie', 'image' => null, 'status' => 'pending', 'watched' => false, 'progress' => null, 'type' => 'movie'],
        ['name' => 'Slow Horses', 'subtitle' => '2022 · Show', 'image' => null, 'status' => 'paused', 'watched' => false, 'progress' => 28, 'type' => 'show'],
        ['name' => 'Abandoned Pilot', 'subtitle' => '2021 · Show', 'image' => null, 'status' => 'abandoned', 'watched' => false, 'progress' => null, 'type' => 'show'],
    ];

    /** @var array<int, array{still: string|null, code: string, name: string, airDate: string|null, runtime: int|null, watched: bool, special: bool}> */
    public array $episodes = [
        ['still' => null, 'code' => 'S01E01', 'name' => 'Good News Is Bad News', 'airDate' => '2022-02-18', 'runtime' => 47, 'watched' => true, 'special' => false],
        ['still' => null, 'code' => 'S01E02', 'name' => 'Half Loop', 'airDate' => '2022-02-18', 'runtime' => 51, 'watched' => true, 'special' => false],
        ['still' => null, 'code' => 'S00E01', 'name' => 'Inside the World of Lumon Industries', 'airDate' => '2022-03-01', 'runtime' => 22, 'watched' => false, 'special' => true],
        ['still' => null, 'code' => 'S01E10', 'name' => 'The We We Are', 'airDate' => null, 'runtime' => 60, 'watched' => false, 'special' => false],
    ];
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-10 pb-24">
    <div>
        <flux:heading size="xl">{{ __('Styleguide') }}</flux:heading>
        <flux:subheading>{{ __('Media components for Screening Room, built from design tokens in resources/css/app.css.') }}</flux:subheading>
    </div>

    {{-- Tokens --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Tokens')" :subheading="__('Surfaces, ink and accent colors. Never hardcode a color — use these utilities.')" />

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
            @foreach (['canvas', 'surface', 'surface-raised', 'line', 'ink', 'ink-muted', 'ink-subtle', 'accent'] as $token)
                <div class="flex flex-col gap-2 rounded-lg border border-line p-3">
                    <div class="h-10 w-full rounded-md bg-{{ $token }} ring-1 ring-line"></div>
                    <span class="text-xs text-ink-subtle">{{ $token }}</span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Badges --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Badges')" :subheading="__('Every tone, variant, and size of x-media.badge — the one badge component used everywhere.')" />

        @foreach ($badgeVariants as $variant)
            <div class="flex flex-col gap-2">
                <span class="text-xs font-medium text-ink-subtle">{{ ucfirst($variant) }}</span>

                @foreach ($badgeSizes as $size)
                    <div class="flex flex-wrap items-center gap-2 {{ $variant === 'solid' ? 'rounded-lg bg-ink/80 p-3' : '' }}">
                        @foreach ($badgeTones as $tone)
                            <x-media.badge :tone="$tone" :variant="$variant" :size="$size" icon="check-circle">
                                {{ ucfirst($tone) }} {{ $size }}
                            </x-media.badge>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endforeach
    </section>

    {{-- Status badges --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Status badges')" :subheading="__('Library status shown on poster cards, via x-media.badge.')" />

        <div class="flex flex-wrap gap-3">
            @foreach ($statuses as $status)
                @php
                    $statusConfig = match ($status) {
                        'available' => ['label' => __('Available'), 'icon' => 'check-circle', 'tone' => 'success'],
                        'requested' => ['label' => __('Requested'), 'icon' => 'clock', 'tone' => 'neutral', 'class' => '!bg-status-requested/15 !text-status-requested'],
                        'pending' => ['label' => __('Pending'), 'icon' => 'clock', 'tone' => 'warning'],
                        'downloading' => ['label' => __('Downloading'), 'icon' => 'arrow-down-tray', 'tone' => 'info'],
                        'watched' => ['label' => __('Watched'), 'icon' => 'check-circle', 'tone' => 'accent'],
                        'abandoned' => ['label' => __('Abandoned'), 'icon' => 'eye-slash', 'tone' => 'neutral', 'class' => '!bg-status-abandoned/15 !text-status-abandoned'],
                        'paused' => ['label' => __('Paused'), 'icon' => 'pause-circle', 'tone' => 'neutral', 'class' => '!bg-status-paused/15 !text-status-paused'],
                    };
                @endphp

                <x-media.badge :tone="$statusConfig['tone']" :icon="$statusConfig['icon']" :class="$statusConfig['class'] ?? ''">
                    {{ $statusConfig['label'] }}
                </x-media.badge>
            @endforeach
        </div>
    </section>

    {{-- Poster card --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Poster card')" :subheading="__('Sizes, statuses, progress, watched indicator, and gradient fallback for missing artwork.')" />

        <div class="flex flex-wrap items-end gap-4">
            <x-media.poster-card
                name="Fight Club"
                subtitle="1999 · Movie"
                image="https://image.tmdb.org/t/p/w342/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg"
                status="available"
                :watched="true"
                size="sm"
                type="movie"
            />

            <x-media.poster-card
                name="Severance"
                subtitle="2022 · Show"
                image="https://image.tmdb.org/t/p/w342/lpalCsSK4YjSk92hbnkFsyBGpDA.jpg"
                status="downloading"
                :progress="62"
                size="md"
                type="show"
            />

            <x-media.poster-card
                name="No Artwork Available Here"
                subtitle="2024 · Show"
                :image="null"
                status="requested"
                size="lg"
                type="show"
            />

            <x-media.poster-card
                name="Untitled"
                :image="null"
                size="md"
                type="movie"
            />
        </div>
    </section>

    {{-- Poster row --}}
    <x-media.poster-row :heading="__('Continue watching')" :subheading="__('Horizontal shelf with keyboard-accessible arrows and scroll-snap.')" href="#">
        @foreach ($posters as $poster)
            <x-media.poster-card
                :name="$poster['name']"
                :subtitle="$poster['subtitle']"
                :image="$poster['image']"
                :status="$poster['status']"
                :watched="$poster['watched']"
                :progress="$poster['progress']"
                :type="$poster['type']"
                href="#"
            />
        @endforeach
    </x-media.poster-row>

    {{-- Poster grid --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Poster grid')" :subheading="__('Responsive grid used on list and search pages.')" />

        <x-media.poster-grid>
            @foreach ($posters as $poster)
                <x-media.poster-card
                    :name="$poster['name']"
                    :subtitle="$poster['subtitle']"
                    :image="$poster['image']"
                    :status="$poster['status']"
                    :watched="$poster['watched']"
                    :progress="$poster['progress']"
                    :type="$poster['type']"
                    size="lg"
                    href="#"
                />
            @endforeach
        </x-media.poster-grid>
    </section>

    {{-- Hero --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Hero')" :subheading="__('Full-bleed backdrop with a gradient scrim and overlapping poster.')" />

        <div class="overflow-hidden rounded-xl ring-1 ring-line">
            <x-media.hero
                backdrop="https://image.tmdb.org/t/p/w1280/hZkgoQYus5vegHoetLkCJzb17zJ.jpg"
                poster="https://image.tmdb.org/t/p/w342/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg"
            >
                <x-slot:title>Fight Club</x-slot:title>

                <x-slot:meta>
                    <x-media.chip>1999</x-media.chip>
                    <x-media.chip>2h 19m</x-media.chip>
                    <x-media.badge tone="success" icon="check-circle">{{ __('Available') }}</x-media.badge>
                </x-slot:meta>

                <x-slot:actions>
                    <flux:button icon="play" variant="primary">{{ __('Play') }}</flux:button>
                    <flux:button icon="plus">{{ __('Add to list') }}</flux:button>
                </x-slot:actions>
            </x-media.hero>
        </div>
    </section>

    {{-- Episode card --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Episode card')" :subheading="__('The one card for every episode/movie-play listing: watched, special, unaired, and movie states, with Plex and the actions menu.')" />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($episodes as $episode)
                <x-media.episode-card
                    :image="$episode['still']"
                    poster="https://image.tmdb.org/t/p/w185/lpalCsSK4YjSk92hbnkFsyBGpDA.jpg"
                    name="Severance"
                    :code="$episode['code']"
                    :episode-name="$episode['name']"
                    :date="$episode['airDate']"
                    :watched="$episode['watched']"
                    :can-mark-watched="! $episode['watched'] && $episode['airDate'] !== null"
                    on-mark-watched="$refresh"
                    :on-unmark-watched="$episode['watched'] ? '$refresh' : null"
                    :muted="$episode['airDate'] === null"
                    plex-available
                    plex-url="#"
                    season-href="#"
                    show-href="#"
                >
                    @if ($episode['special'])
                        <x-slot:chip>
                            <x-media.chip>{{ __('Special') }}</x-media.chip>
                        </x-slot:chip>
                    @endif
                </x-media.episode-card>
            @endforeach

            <x-media.episode-card
                image="https://image.tmdb.org/t/p/w300/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg"
                poster="https://image.tmdb.org/t/p/w185/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg"
                name="Fight Club"
                meta="1999"
                date="Watched 8:42pm"
                source="Plex"
                watched
                on-remove="$refresh"
                remove-confirm="Remove this play from history?"
            />
        </div>
    </section>

    {{-- Stat + chip --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Stats & chips')" />

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-media.stat label="Movies watched" value="128" />
            <x-media.stat label="Episodes watched" value="1,942" />
            <x-media.stat label="Shows in progress" value="6" />
            <x-media.stat label="Watchlist" value="34" />
        </div>

        <div class="flex flex-wrap gap-2">
            <x-media.chip>Drama</x-media.chip>
            <x-media.chip>Sci-Fi</x-media.chip>
            <x-media.chip>2h 19m</x-media.chip>
        </div>
    </section>

    {{-- Empty state --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Empty state')" />

        <x-media.empty-state icon="film" :heading="__('Nothing here yet')">
            {{ __('Titles you add will show up here.') }}
        </x-media.empty-state>
    </section>

    {{-- Card --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Card')" :subheading="__('The panel wrapper for grouped content: flux:card in the outline variant, on the app surface and border tokens.')" />

        <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
            <flux:card.header>
                <flux:card.heading size="lg">{{ __('Panel title') }}</flux:card.heading>
                <flux:card.subheading>{{ __('Optional supporting line') }}</flux:card.subheading>
                <flux:card.actions>
                    <x-media.chip>{{ __('Action') }}</x-media.chip>
                </flux:card.actions>
            </flux:card.header>

            <flux:card.body>
                <p class="text-sm text-ink-muted">{{ __('Panel content goes in flux:card.body.') }}</p>
            </flux:card.body>
        </flux:card>
    </section>

    {{-- Light / dark preview --}}
    <section class="flex flex-col gap-3">
        <x-media.section-header :heading="__('Light / dark preview')" :subheading="__('Tokens react to the .dark class scope, independent of the global appearance setting.')" />

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-4 rounded-xl bg-canvas p-6 ring-1 ring-line">
                <span class="text-xs font-medium uppercase tracking-wide text-ink-subtle">{{ __('Light') }}</span>

                <div class="flex flex-wrap gap-3">
                    <x-media.poster-card name="Fight Club" subtitle="1999 · Movie" image="https://image.tmdb.org/t/p/w342/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg" status="watched" :watched="true" size="sm" />
                    <x-media.badge tone="info" icon="arrow-down-tray">{{ __('Downloading') }}</x-media.badge>
                </div>
            </div>

            <div class="dark flex flex-col gap-4 rounded-xl bg-canvas p-6 ring-1 ring-line">
                <span class="text-xs font-medium uppercase tracking-wide text-ink-subtle">{{ __('Dark') }}</span>

                <div class="flex flex-wrap gap-3">
                    <x-media.poster-card name="Fight Club" subtitle="1999 · Movie" image="https://image.tmdb.org/t/p/w342/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg" status="watched" :watched="true" size="sm" />
                    <x-media.badge tone="info" icon="arrow-down-tray">{{ __('Downloading') }}</x-media.badge>
                </div>
            </div>
        </div>
    </section>
</div>
