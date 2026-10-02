<?php

use App\Enums\ListReleaseFilter;
use App\Enums\ListSort;
use App\Enums\ShowStatus;
use App\Enums\TitleType;
use App\Models\MediaList;
use App\Models\Network;
use App\Models\WatchProvider;
use App\Services\Collection\Ownership;
use App\Services\Lists\ListItemQuery;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public MediaList $mediaList;

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'sort', except: 'manual')]
    public string $sort = 'manual';

    #[Url(as: 'type', except: '')]
    public string $type = '';

    #[Url(as: 'unwatched', except: false)]
    public bool $unwatched = false;

    #[Url(as: 'release', except: '')]
    public string $release = '';

    #[Url(as: 'service', except: '')]
    public string $service = '';

    #[Url(as: 'network', except: '')]
    public string $network = '';

    #[Computed]
    public function items()
    {
        return app(ListItemQuery::class)->forInput(
            $this->mediaList,
            $this->sort,
            $this->type,
            $this->statusFilter,
            $this->unwatched,
            $this->release,
            $this->providerFilter(),
            $this->networkFilter(),
        );
    }

    /**
     * The distinct providers (in the current region) carrying this list's titles.
     *
     * @return Collection<int, WatchProvider>
     */
    #[Computed]
    public function serviceOptions()
    {
        if (! app(TmdbClient::class)->showWatchProviders()) {
            return new Collection;
        }

        return WatchProvider::query()
            ->whereIn('watch_providers.id', DB::table('title_watch_provider')
                ->select('watch_provider_id')
                ->whereIn('title_id', $this->mediaList->items()->select('title_id'))
                ->where('region', app(TmdbClient::class)->region()))
            ->orderBy('display_priority')
            ->orderBy('name')
            ->get();
    }

    /**
     * The distinct networks carrying this list's titles.
     *
     * @return Collection<int, Network>
     */
    #[Computed]
    public function networkOptions()
    {
        return Network::query()
            ->whereIn('networks.id', DB::table('network_title')
                ->select('network_id')
                ->whereIn('title_id', $this->mediaList->items()->select('title_id')))
            ->orderBy('name')
            ->get();
    }

    private function providerFilter(): ?int
    {
        return app(TmdbClient::class)->showWatchProviders() && ctype_digit($this->service) ? (int) $this->service : null;
    }

    private function networkFilter(): ?int
    {
        return ctype_digit($this->network) ? (int) $this->network : null;
    }

    #[Computed]
    public function hasActiveFilters(): bool
    {
        return TitleType::tryFrom($this->type) !== null
            || ShowStatus::tryFrom($this->statusFilter) !== null
            || ListReleaseFilter::tryFrom($this->release) !== null
            || $this->providerFilter() !== null
            || $this->networkFilter() !== null
            || $this->unwatched;
    }

    #[Computed]
    public function canReorder(): bool
    {
        return (ListSort::tryFrom($this->sort) ?? ListSort::Manual) === ListSort::Manual && ! $this->hasActiveFilters;
    }

    #[Computed]
    public function ownershipSummaries(): array
    {
        $titles = $this->items->pluck('title');

        return app(Ownership::class)->forTitles($titles);
    }

    #[Renderless]
    public function removeItem(int $titleId): void
    {
        $this->mediaList->items()->where('title_id', $titleId)->get()->each->delete();

        unset($this->items);
    }

    #[Renderless]
    public function handleSort(int $titleId, int $position): void
    {
        // Dragged positions are only meaningful against the full, unfiltered
        // manual order, so ignore reorder attempts while a sort or filter is active.
        if (! $this->canReorder) {
            return;
        }

        $items = $this->mediaList->items()->orderBy('position')->get();

        $moving = $items->firstWhere('title_id', $titleId);

        if ($moving === null) {
            return;
        }

        $ordered = $items->reject(fn ($item) => $item->id === $moving->id)->values();
        $ordered->splice($position, 0, [$moving]);

        foreach ($ordered->values() as $index => $item) {
            if ($item->position !== $index) {
                $item->update(['position' => $index]);
            }
        }

        unset($this->items);
    }

    public function render()
    {
        return $this->view()->title($this->mediaList->name);
    }
}; ?>

<div class="flex flex-col gap-6 py-6">
    <div class="flex flex-col gap-2">
        <flux:link href="{{ route('lists.index') }}" wire:navigate class="text-sm">
            {{ __('← Back to lists') }}
        </flux:link>

        <div class="flex items-center gap-2">
            @if ($mediaList->is_watchlist)
                <flux:icon.bookmark variant="solid" class="size-5 text-accent" />
            @endif

            <flux:heading size="xl">{{ $mediaList->name }}</flux:heading>
        </div>

        @if ($mediaList->description)
            <flux:subheading>{{ $mediaList->description }}</flux:subheading>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <flux:select wire:model.live="sort" class="w-48" :aria-label="__('Sort by')">
            @foreach (ListSort::cases() as $sortOption)
                <flux:select.option value="{{ $sortOption->value }}">{{ $sortOption->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="type" class="w-40" :placeholder="__('All types')">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            <flux:select.option value="movie">{{ __('Movies') }}</flux:select.option>
            <flux:select.option value="show">{{ __('Shows') }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="statusFilter" class="w-48" :placeholder="__('All statuses')">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (ShowStatus::cases() as $status)
                <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="release" class="w-44" :placeholder="__('Any release')">
            <flux:select.option value="">{{ __('Any release') }}</flux:select.option>
            @foreach (ListReleaseFilter::cases() as $releaseOption)
                <flux:select.option value="{{ $releaseOption->value }}">{{ $releaseOption->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if (app(TmdbClient::class)->showWatchProviders() && ($this->serviceOptions->isNotEmpty() || $service !== ''))
            <flux:select wire:model.live="service" class="w-48" :placeholder="__('Any service')">
                <flux:select.option value="">{{ __('Any service') }}</flux:select.option>
                @foreach ($this->serviceOptions as $option)
                    <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if ($this->networkOptions->isNotEmpty() || $network !== '')
            <flux:select wire:model.live="network" class="w-48" :placeholder="__('Any network')">
                <flux:select.option value="">{{ __('Any network') }}</flux:select.option>
                @foreach ($this->networkOptions as $option)
                    <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:switch wire:model.live="unwatched" :label="__('Unwatched only')" />
    </div>

    @if ($this->items->isEmpty())
        <x-media.empty-state icon="film" :heading="$this->hasActiveFilters ? __('No titles match these filters') : __('No titles yet')">
            {{ $this->hasActiveFilters ? __('Try changing or clearing the filters.') : __('Add movies and shows to this list from their detail page.') }}
        </x-media.empty-state>
    @else
        <svg class="absolute size-0" aria-hidden="true">
            <symbol id="list-icon-handle" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5"/></symbol>
            <symbol id="list-icon-remove" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></symbol>
        </svg>
        <x-media.poster-grid :wire:sort="$this->canReorder ? 'handleSort($item, $position).catch(() => $wire.$refresh())' : null">
@foreach ($this->items as $item)
@php
$title = $item->title;
$releaseDate = $title->isMovie() ? $title->release_date : $title->last_air_date;
$summary = $this->ownershipSummaries[$title->id] ?? null;
@endphp
<div wire:key="item-{{ $item->id }}" wire:sort:item="{{ $item->title_id }}" x-data="optimistic(false)" x-show="!value" x-transition.opacity.duration.200ms class="group/item relative">
<x-media.poster-card :image="$title->posterUrl()" :name="$title->name" :subtitle="$releaseDate?->format('Y')" :href="route('titles.show', $title)" :type="$title->type->value" :status="$title->libraryStatus?->state->value" size="lg">
@if ($summary && $summary->isOwned())
<x-slot:badge>
<x-media.owned-badge :summary="$summary" />
</x-slot:badge>
@endif
</x-media.poster-card>
@if ($this->canReorder)
<div wire:sort:handle aria-hidden="true" class="list-item-control left-2 cursor-grab text-ink active:cursor-grabbing"><svg class="size-4"><use href="#list-icon-handle"/></svg></div>
@endif
<button type="button" wire:sort:ignore x-on:click="set(true, () => $wire.removeItem({{ $item->title_id }}))" aria-label="{{ __('Remove from list') }}" class="list-item-control right-2 text-ink-subtle hover:text-status-abandoned"><svg class="size-4"><use href="#list-icon-remove"/></svg></button>
</div>
@endforeach
        </x-media.poster-grid>
    @endif
</div>
