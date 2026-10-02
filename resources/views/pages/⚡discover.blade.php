<?php

use App\Services\Discover\DiscoverFeed;
use App\Services\Discover\DiscoverItem;
use App\Services\Discover\DiscoverShelf;
use App\Services\Tmdb\TmdbException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title as PageTitle;
use Livewire\Component;

new #[PageTitle('Discover')] class extends Component
{
    #[Session(key: 'discover.hide_watched')]
    public bool $hideWatched = true;

    /**
     * @return array{items: array<int, array<string, mixed>>, error: ?string}
     */
    #[Computed]
    public function trending(): array
    {
        return $this->loadSection(
            fn (): array => app(DiscoverFeed::class)->trending(),
            __('Could not load trending titles right now.'),
        );
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, error: ?string}
     */
    #[Computed]
    public function newMovies(): array
    {
        return $this->loadSection(
            fn (): array => app(DiscoverFeed::class)->inTheatersAndComingSoon(),
            __('Could not load new and upcoming movies right now.'),
        );
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, error: ?string}
     */
    #[Computed]
    public function newEpisodes(): array
    {
        return $this->loadSection(
            fn (): array => app(DiscoverFeed::class)->newEpisodesThisWeek(),
            __('Could not load new episodes right now.'),
        );
    }

    /**
     * @return array{shelves: array<int, array{heading: string, items: array<int, array<string, mixed>>}>, error: ?string}
     */
    #[Computed]
    public function recommendations(): array
    {
        try {
            $shelves = collect(app(DiscoverFeed::class)->becauseYouWatched())
                ->map(fn (DiscoverShelf $shelf): array => [
                    'heading' => $shelf->heading,
                    'items' => $this->serialize($shelf->items),
                ])
                ->all();

            return ['shelves' => $shelves, 'error' => null];
        } catch (TmdbException) {
            return ['shelves' => [], 'error' => __('Could not load recommendations right now.')];
        }
    }

    /**
     * @param  callable(): array<int, DiscoverItem>  $fetch
     * @return array{items: array<int, array<string, mixed>>, error: ?string}
     */
    private function loadSection(callable $fetch, string $errorMessage): array
    {
        try {
            return ['items' => $this->serialize($fetch()), 'error' => null];
        } catch (TmdbException) {
            return ['items' => [], 'error' => $errorMessage];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function anyNotWatched(array $items): bool
    {
        return collect($items)->contains(fn (array $item): bool => ! $item['watched']);
    }

    public function anyItemsLoaded(): bool
    {
        return $this->trending['items'] !== []
            || $this->newMovies['items'] !== []
            || $this->newEpisodes['items'] !== []
            || collect($this->recommendations['shelves'])->contains(fn (array $shelf): bool => $shelf['items'] !== []);
    }

    public function anyItemNotWatchedAnywhere(): bool
    {
        return $this->anyNotWatched($this->trending['items'])
            || $this->anyNotWatched($this->newMovies['items'])
            || $this->anyNotWatched($this->newEpisodes['items'])
            || collect($this->recommendations['shelves'])->contains(fn (array $shelf): bool => $this->anyNotWatched($shelf['items']));
    }

    /**
     * @param  array<int, DiscoverItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function serialize(array $items): array
    {
        return collect($items)
            ->map(fn (DiscoverItem $item): array => [
                'tmdb_id' => $item->tmdbId,
                'type' => $item->type->value,
                'name' => $item->name,
                'year' => $item->year,
                'poster' => $item->poster,
                'href' => $item->title
                    ? route('titles.show', $item->title)
                    : route('titles.tmdb', array_filter([
                        'type' => $item->type->value,
                        'tmdbId' => $item->tmdbId,
                        'name' => $item->name,
                        'poster' => $item->poster,
                        'year' => $item->year,
                    ], fn (mixed $value): bool => $value !== null)),
                'watched' => $item->watched,
                'status' => $item->status,
                'on_list' => $item->onList,
                'followed' => $item->followed && ! $item->watched,
                'is_re_release' => $item->isReRelease,
            ])
            ->all();
    }
}; ?>

<div
    class="flex h-full w-full flex-1 flex-col gap-8 rounded-xl"
    x-data="{ hideWatched: localStorage.getItem('discover.hideWatched') === null ? {{ $hideWatched ? 'true' : 'false' }} : localStorage.getItem('discover.hideWatched') === 'true' }"
    x-effect="localStorage.setItem('discover.hideWatched', hideWatched)"
>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-0.5">
            <flux:heading size="xl">{{ __('Discover') }}</flux:heading>
            <flux:subheading>{{ __('Popular and new movies and shows from TMDB.') }}</flux:subheading>
        </div>

        <label class="flex items-center gap-2 text-sm text-ink-muted">
            <flux:switch :checked="$hideWatched" x-on:input="hideWatched = $event.target.checked" x-effect="$el.checked = hideWatched" />
            {{ __("Hide what I've seen") }}
        </label>
    </div>

    <div class="flex flex-col gap-3">
        @if ($this->trending['error'])
            <x-media.section-header :heading="__('Trending this week')" />
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="$this->trending['error']" />
        @elseif ($this->trending['items'] !== [])
            <x-media.poster-row :heading="__('Trending this week')" x-show="!hideWatched || {{ $this->anyNotWatched($this->trending['items']) ? 'true' : 'false' }}">
                @foreach ($this->trending['items'] as $item)
                    <x-discover.card :item="$item" wire:key="trending-{{ $item['type'] }}-{{ $item['tmdb_id'] }}" x-show="!hideWatched || {{ $item['watched'] ? 'false' : 'true' }}" />
                @endforeach
            </x-media.poster-row>
        @endif
    </div>

    <div class="flex flex-col gap-3">
        @if ($this->newMovies['error'])
            <x-media.section-header :heading="__('In theaters & coming soon')" />
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="$this->newMovies['error']" />
        @elseif ($this->newMovies['items'] !== [])
            <x-media.poster-row :heading="__('In theaters & coming soon')" x-show="!hideWatched || {{ $this->anyNotWatched($this->newMovies['items']) ? 'true' : 'false' }}">
                @foreach ($this->newMovies['items'] as $item)
                    <x-discover.card :item="$item" wire:key="new-movies-{{ $item['type'] }}-{{ $item['tmdb_id'] }}" x-show="!hideWatched || {{ $item['watched'] ? 'false' : 'true' }}" />
                @endforeach
            </x-media.poster-row>
        @endif
    </div>

    <div class="flex flex-col gap-3">
        @if ($this->newEpisodes['error'])
            <x-media.section-header :heading="__('New episodes this week')" />
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="$this->newEpisodes['error']" />
        @elseif ($this->newEpisodes['items'] !== [])
            <x-media.poster-row :heading="__('New episodes this week')" x-show="!hideWatched || {{ $this->anyNotWatched($this->newEpisodes['items']) ? 'true' : 'false' }}">
                @foreach ($this->newEpisodes['items'] as $item)
                    <x-discover.card :item="$item" wire:key="new-episodes-{{ $item['type'] }}-{{ $item['tmdb_id'] }}" x-show="!hideWatched || {{ $item['watched'] ? 'false' : 'true' }}" />
                @endforeach
            </x-media.poster-row>
        @endif
    </div>

    <div class="flex flex-col gap-8">
        @if ($this->recommendations['error'])
            <x-media.section-header :heading="__('Because you watched…')" />
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="$this->recommendations['error']" />
        @else
            @foreach ($this->recommendations['shelves'] as $shelf)
                @continue ($shelf['items'] === [])

                <x-media.poster-row :heading="$shelf['heading']" x-show="!hideWatched || {{ $this->anyNotWatched($shelf['items']) ? 'true' : 'false' }}">
                    @foreach ($shelf['items'] as $item)
                        <x-discover.card :item="$item" wire:key="recs-{{ $loop->parent->index }}-{{ $item['type'] }}-{{ $item['tmdb_id'] }}" x-show="!hideWatched || {{ $item['watched'] ? 'false' : 'true' }}" />
                    @endforeach
                </x-media.poster-row>
            @endforeach
        @endif
    </div>

    @if (! $this->trending['error'] && ! $this->newMovies['error'] && ! $this->newEpisodes['error'] && ! $this->recommendations['error'])
        <x-media.empty-state
            icon="film"
            :heading="__('Nothing new to discover')"
            x-show="{{ $this->anyItemsLoaded() ? 'false' : 'true' }} || (hideWatched && {{ $this->anyItemNotWatchedAnywhere() ? 'false' : 'true' }})"
        >
            {{ __("You're all caught up. Check back later for new titles.") }}
        </x-media.empty-state>
    @endif
</div>
