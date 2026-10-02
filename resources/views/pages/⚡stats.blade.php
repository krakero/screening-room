<?php

use App\Services\Collection\CollectionStats;
use App\Services\Stats\StatsService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Stats')] class extends Component
{
    #[Url]
    public ?int $year = null;

    public bool $statsLoaded = false;

    /** @var array<string, mixed> */
    public array $stats = [];

    /** @var array<string, mixed> */
    public array $collectionStats = [];

    /** @var array<int, int> */
    public array $availableYears = [];

    /**
     * Deferred behind `wire:init` (see the page markup) so the ~10 heavy aggregate queries in
     * `StatsService::summary()` never block first paint — the page renders its skeleton
     * immediately, then this follow-up request fills it in, same shape as the Discover page's
     * per-shelf loading. On a warm cache (the common case, 24h TTL) this is fast; on a cold
     * cache it's the one request that pays for it, not the page's first paint.
     */
    public function loadStats(): void
    {
        $this->availableYears = app(StatsService::class)->availableYears();
        $this->stats = app(StatsService::class)->summary($this->year);

        if (auth()->user()->collection_enabled) {
            $this->collectionStats = app(CollectionStats::class)->summary($this->year);
        }

        $this->statsLoaded = true;
    }

    /**
     * The year filter (`wire:model.live`) already triggers its own Livewire request; reload the
     * summary for the newly selected year within it rather than waiting on a second `wire:init`
     * round trip (which only fires once, on initial mount).
     */
    public function updatedYear(): void
    {
        $this->stats = app(StatsService::class)->summary($this->year);

        if (auth()->user()->collection_enabled) {
            $this->collectionStats = app(CollectionStats::class)->summary($this->year);
        }
    }

    public function formatMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours === 0) {
            return __(':mins m', ['mins' => $mins]);
        }

        return __(':hours h :mins m', ['hours' => $hours, 'mins' => $mins]);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-8 rounded-xl" wire:init="loadStats">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-0.5">
            <flux:heading size="xl">{{ __('Stats') }}</flux:heading>
            <flux:subheading>{{ __('Everything you\'ve watched, at a glance.') }}</flux:subheading>
        </div>

        @if ($statsLoaded && ! empty($availableYears))
            <flux:select wire:model.live="year" class="w-40" :placeholder="__('All time')">
                <flux:select.option value="">{{ __('All time') }}</flux:select.option>
                @foreach ($availableYears as $availableYear)
                    <flux:select.option value="{{ $availableYear }}">{{ $availableYear }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if (! $statsLoaded)
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @for ($i = 0; $i < 6; $i++)
                <div class="h-16 animate-pulse rounded-xl bg-surface-raised"></div>
            @endfor
        </section>

        <div class="h-48 animate-pulse rounded-xl bg-surface-raised"></div>
        <div class="h-40 animate-pulse rounded-xl bg-surface-raised"></div>
    @elseif (($stats['headline']['moviesWatched'] ?? 0) === 0 && ($stats['headline']['episodesWatched'] ?? 0) === 0)
        <x-media.empty-state icon="chart-bar" :heading="__('Nothing watched yet')">
            {{ __('Mark movies and episodes watched to see your stats build up here.') }}
        </x-media.empty-state>
    @else
        @php
            $headline = $stats['headline'];
        @endphp

        <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <x-media.stat :label="__('Total watch time')" :value="$this->formatMinutes($headline['totalWatchMinutes'])" />
            <x-media.stat :label="__('Movies watched')" :value="number_format($headline['moviesWatched'])" />
            <x-media.stat :label="__('Episodes watched')" :value="number_format($headline['episodesWatched'])" />
            <x-media.stat :label="__('Shows followed')" :value="number_format($headline['showsFollowed'])" />
            <x-media.stat :label="__('Shows completed')" :value="number_format($headline['showsCompleted'])" />
            <x-media.stat :label="__('Plays this year')" :value="number_format($headline['playsThisYear'])" />
        </section>

        @if ($stats['streaks'])
            <section class="grid grid-cols-2 gap-3 sm:w-1/2">
                <x-media.stat :label="__('Current streak')" :value="__(':n days', ['n' => $stats['streaks']['current']])" />
                <x-media.stat :label="__('Longest streak')" :value="__(':n days', ['n' => $stats['streaks']['longest']])" />
            </section>
        @endif

        @if ($stats['monthly'])
            @php
                $monthly = $stats['monthly'];
                $months = $monthly['months'];
                $ticks = $monthly['ticks'];
                $columns = 'grid-template-columns: repeat('.count($months).', minmax(1.25rem, 1fr));';
            @endphp

            <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                <flux:card.header>
                    <flux:card.heading size="lg">{{ __('Plays per month') }}</flux:card.heading>
                    <flux:card.subheading>{{ __('Last 24 months') }}</flux:card.subheading>
                    <flux:card.actions>
                        <span class="flex items-center gap-1.5 text-xs text-ink-muted">
                            <span class="size-2 rounded-full bg-accent"></span> {{ __('Episodes') }}
                        </span>
                        <span class="flex items-center gap-1.5 text-xs text-ink-muted">
                            <span class="size-2 rounded-full bg-ink-subtle"></span> {{ __('Movies') }}
                        </span>
                    </flux:card.actions>
                </flux:card.header>

                <flux:card.body>
                <div class="overflow-x-auto" data-testid="monthly-plays-chart">
                    <div class="relative h-48" style="min-width: max(100%, {{ count($months) * 1.25 + 2.5 }}rem)">
                        <div class="absolute inset-0 flex flex-col justify-between pb-px">
                            @foreach (array_reverse($ticks) as $tick)
                                <div class="flex items-center gap-2">
                                    <span class="w-8 shrink-0 text-right text-[10px] tabular-nums text-ink-subtle">{{ number_format($tick) }}</span>
                                    <span class="h-px w-full bg-line"></span>
                                </div>
                            @endforeach
                        </div>

                        <div class="absolute inset-0 grid items-end gap-1 pl-10" style="{{ $columns }}">
                            @foreach ($months as $month)
                                <div
                                    class="group/bar relative flex h-full flex-col items-center justify-end"
                                    tabindex="0"
                                    role="img"
                                    aria-label="{{ $month['month'] }}: {{ __(':n movie plays', ['n' => $month['movies']]) }}, {{ __(':n episode plays', ['n' => $month['episodes']]) }}"
                                >
                                    <div class="pointer-events-none absolute bottom-full z-10 mb-2 hidden w-max flex-col gap-0.5 rounded-lg bg-surface-raised p-2 text-xs whitespace-nowrap text-ink shadow-lg ring-1 ring-line group-hover/bar:flex group-focus-visible/bar:flex">
                                        <span class="font-medium">{{ $month['month'] }}</span>
                                        <span class="text-ink-muted">{{ __(':n episodes', ['n' => $month['episodes']]) }}</span>
                                        <span class="text-ink-muted">{{ __(':n movies', ['n' => $month['movies']]) }}</span>
                                        <span class="text-ink-subtle">{{ __(':n total', ['n' => $month['total']]) }}</span>
                                    </div>

                                    <div class="flex w-full flex-col-reverse overflow-hidden rounded-t ring-2 ring-transparent group-focus-visible/bar:ring-accent" style="height: {{ $month['moviesPct'] + $month['episodesPct'] }}%">
                                        <div class="w-full bg-ink-subtle" style="height: {{ $month['total'] > 0 ? ($month['moviesPct'] / ($month['moviesPct'] + $month['episodesPct'])) * 100 : 0 }}%"></div>
                                        <div class="w-full bg-accent" style="height: {{ $month['total'] > 0 ? ($month['episodesPct'] / ($month['moviesPct'] + $month['episodesPct'])) * 100 : 0 }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid gap-1 pt-2 pl-10" style="{{ $columns }}">
                        @foreach ($months as $month)
                            <span class="truncate text-center text-[10px] text-ink-subtle {{ $month['labelMobile'] ? '' : 'max-sm:invisible' }} {{ $month['labelDesktop'] ? '' : 'sm:invisible' }}">
                                {{ $month['labelMobile'] || $month['labelDesktop'] ? $month['month'] : '' }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <table class="sr-only">
                    <caption>{{ __('Plays per month, last 24 months') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Month') }}</th>
                            <th scope="col">{{ __('Movies') }}</th>
                            <th scope="col">{{ __('Episodes') }}</th>
                            <th scope="col">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($months as $month)
                            <tr>
                                <td>{{ $month['month'] }}</td>
                                <td>{{ $month['movies'] }}</td>
                                <td>{{ $month['episodes'] }}</td>
                                <td>{{ $month['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </flux:card.body>
            </flux:card>
        @endif

        @php
            $heatmap = $stats['heatmap'];
            $maxHeat = max(1, collect($heatmap)->flatten()->max());
            $weekdays = [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')];
        @endphp

        <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
            <flux:card.header>
                <flux:card.heading size="lg">{{ __('When you watch') }}</flux:card.heading>
                <flux:card.subheading>{{ __('Minutes watched by day and hour') }}</flux:card.subheading>
            </flux:card.header>

            <flux:card.body>
            <div class="overflow-x-auto">
                <div class="flex flex-col gap-1">
                    @foreach ($weekdays as $dow => $label)
                        <div class="flex items-center gap-1">
                            <span class="w-8 shrink-0 text-[10px] text-ink-subtle">{{ $label }}</span>
                            <div class="flex gap-0.5">
                                @for ($hour = 0; $hour < 24; $hour++)
                                    @php
                                        $minutes = $heatmap[$dow][$hour] ?? 0;
                                    @endphp
                                    <div
                                        class="size-3 rounded-[3px] bg-accent"
                                        style="opacity: {{ $minutes === 0 ? 0.08 : max(0.15, $minutes / $maxHeat) }}"
                                        title="{{ $label }} {{ $hour }}:00 — {{ $minutes }} {{ __('min') }}"
                                    ></div>
                                @endfor
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            </flux:card.body>
        </flux:card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                <flux:card.header>
                    <flux:card.heading size="lg">{{ __('Most-watched shows') }}</flux:card.heading>
                    <flux:card.subheading>{{ __('By episodes played') }}</flux:card.subheading>
                </flux:card.header>
                <flux:card.body>

                <div class="flex flex-col gap-2">
                    @forelse ($stats['topShows']['byEpisodes'] as $show)
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-surface p-2 px-3 ring-1 ring-line">
                            <span class="truncate text-sm text-ink">{{ $show['name'] }}</span>
                            <span class="shrink-0 text-xs text-ink-muted">{{ __(':n episodes', ['n' => $show['episode_plays']]) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-ink-subtle">{{ __('Nothing yet.') }}</p>
                    @endforelse
                </div>
                </flux:card.body>
            </flux:card>

            <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                <flux:card.header>
                    <flux:card.heading size="lg">{{ __('Most-watched shows') }}</flux:card.heading>
                    <flux:card.subheading>{{ __('By hours watched') }}</flux:card.subheading>
                </flux:card.header>
                <flux:card.body>

                <div class="flex flex-col gap-2">
                    @forelse ($stats['topShows']['byHours'] as $show)
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-surface p-2 px-3 ring-1 ring-line">
                            <span class="truncate text-sm text-ink">{{ $show['name'] }}</span>
                            <span class="shrink-0 text-xs text-ink-muted">{{ $this->formatMinutes($show['minutes']) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-ink-subtle">{{ __('Nothing yet.') }}</p>
                    @endforelse
                </div>
                </flux:card.body>
            </flux:card>

            <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                <flux:card.header>
                    <flux:card.heading size="lg">{{ __('Top genres') }}</flux:card.heading>
                </flux:card.header>
                <flux:card.body>

                <div class="flex flex-wrap gap-2">
                    @forelse ($stats['topGenres'] as $genre)
                        <x-media.chip>{{ $genre['genre'] }} · {{ $genre['count'] }}</x-media.chip>
                    @empty
                        <p class="text-sm text-ink-subtle">{{ __('Nothing yet.') }}</p>
                    @endforelse
                </div>
                </flux:card.body>
            </flux:card>

            <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                <flux:card.header>
                    <flux:card.heading size="lg">{{ __('Average rating by genre') }}</flux:card.heading>
                </flux:card.header>
                <flux:card.body>

                <div class="flex flex-wrap gap-2">
                    @forelse ($stats['averageRatingByGenre'] as $genre)
                        <x-media.chip>{{ $genre['genre'] }} · {{ number_format($genre['average'], 1) }}★ ({{ $genre['count'] }})</x-media.chip>
                    @empty
                        <p class="text-sm text-ink-subtle">{{ __('Nothing yet.') }}</p>
                    @endforelse
                </div>
                </flux:card.body>
            </flux:card>

            <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                <flux:card.header>
                    <flux:card.heading size="lg">{{ __('Most rewatched') }}</flux:card.heading>
                </flux:card.header>
                <flux:card.body>

                <div class="flex flex-col gap-2">
                    @forelse ($stats['mostRewatched'] as $item)
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-surface p-2 px-3 ring-1 ring-line">
                            <span class="truncate text-sm text-ink">{{ $item['name'] }}</span>
                            <span class="shrink-0 text-xs text-ink-muted">{{ __(':n plays', ['n' => $item['plays']]) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-ink-subtle">{{ __('Nothing rewatched yet.') }}</p>
                    @endforelse
                </div>
                </flux:card.body>
            </flux:card>

            <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                <flux:card.header>
                    <flux:card.heading size="lg">{{ __('Plays by source') }}</flux:card.heading>
                </flux:card.header>
                <flux:card.body>

                @php
                    $totalSourcePlays = max(1, array_sum($stats['sources']));
                @endphp

                <div class="flex flex-col gap-2">
                    @forelse ($stats['sources'] as $source => $count)
                        <div class="flex items-center gap-3">
                            <span class="w-16 shrink-0 text-xs text-ink-muted capitalize">{{ $source }}</span>
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-surface-raised">
                                <div class="h-full rounded-full bg-accent" style="width: {{ ($count / $totalSourcePlays) * 100 }}%"></div>
                            </div>
                            <span class="w-10 shrink-0 text-right text-xs text-ink-muted">{{ $count }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-ink-subtle">{{ __('Nothing yet.') }}</p>
                    @endforelse
                </div>
                </flux:card.body>
            </flux:card>
        </div>

        @if (auth()->user()->collection_enabled && ($collectionStats['copies'] ?? 0) > 0)
            <section class="flex flex-col gap-4">
                <div class="flex flex-col gap-0.5">
                    <flux:heading size="lg">{{ __('Collection') }}</flux:heading>
                    <flux:subheading>{{ __('Your physical and digital media library.') }}</flux:subheading>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <x-media.stat :label="__('Total copies')" :value="number_format($collectionStats['copies'])" />
                    <x-media.stat :label="__('Titles owned')" :value="number_format($collectionStats['titles'])" />
                    <x-media.stat :label="__('Movies')" :value="number_format($collectionStats['movies'])" />
                    <x-media.stat :label="__('Shows')" :value="number_format($collectionStats['shows'])" />
                    <x-media.stat :label="__('Loaned out')" :value="number_format($collectionStats['loaned_out'])" />
                    <x-media.stat :label="__('Added this year')" :value="number_format($collectionStats['added_this_year'])" />
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    @if (! empty($collectionStats['by_format']))
                        <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                            <flux:card.header>
                                <flux:card.heading size="lg">{{ __('By format') }}</flux:card.heading>
                            </flux:card.header>
                            <flux:card.body>

                            <div class="flex flex-col gap-2">
                                @foreach ($collectionStats['by_format'] as $formatValue => $count)
                                    @php
                                        $format = \App\Enums\CollectionFormat::from($formatValue);
                                    @endphp
                                    <div class="flex items-center justify-between gap-3 rounded-lg bg-surface p-2 px-3 ring-1 ring-line">
                                        <span class="truncate text-sm text-ink">{{ $format->label() }}</span>
                                        <span class="shrink-0 text-xs text-ink-muted">{{ number_format($count) }}</span>
                                    </div>
                                @endforeach
                            </div>
                            </flux:card.body>
                        </flux:card>
                    @endif

                    @if (! empty($collectionStats['total_spent']))
                        <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                            <flux:card.header>
                                <flux:card.heading size="lg">{{ __('Total spent') }}</flux:card.heading>
                            </flux:card.header>
                            <flux:card.body>

                            <div class="flex flex-col gap-2">
                                @foreach ($collectionStats['total_spent'] as $currency => $total)
                                    <div class="flex items-center justify-between gap-3 rounded-lg bg-surface p-2 px-3 ring-1 ring-line">
                                        <span class="truncate text-sm text-ink uppercase">{{ $currency }}</span>
                                        <span class="shrink-0 text-xs text-ink-muted">{{ number_format($total, 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                            </flux:card.body>
                        </flux:card>
                    @endif

                    @if ($collectionStats['in_plex_only'] > 0)
                        <flux:card variant="outline" :highlight="false" class="border-line bg-surface">
                            <flux:card.header>
                                <flux:card.heading size="lg">{{ __('Plex only') }}</flux:card.heading>
                                <flux:card.subheading>{{ __('Titles in Plex without a physical/digital copy') }}</flux:card.subheading>
                            </flux:card.header>
                            <flux:card.body>

                            <div class="flex items-center justify-center py-4">
                                <span class="text-3xl font-semibold text-ink">{{ number_format($collectionStats['in_plex_only']) }}</span>
                            </div>
                            </flux:card.body>
                        </flux:card>
                    @endif
                </div>
            </section>
        @endif
    @endif
</div>
