<?php

use App\Enums\CollectionFormat;
use App\Enums\TitleType;
use App\Services\Collection\CollectionQuery;
use App\Services\Collection\Ownership;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title as PageTitle;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[PageTitle('Collection')] class extends Component
{
    use WithPagination;

    #[Url(as: 'format', except: '')]
    public string $formatFilter = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'loaned', except: '')]
    public string $loanedFilter = '';

    #[Url(as: 'plex', except: false)]
    public bool $plexOnly = false;

    #[Url(as: 'search', except: '')]
    public string $search = '';

    #[Url(as: 'sort', except: 'title')]
    public string $sort = 'title';

    #[Computed]
    public function titles()
    {
        $query = app(CollectionQuery::class);

        if ($this->formatFilter !== '') {
            $format = CollectionFormat::tryFrom($this->formatFilter);
            if ($format) {
                $query->format($format);
            }
        }

        if ($this->typeFilter !== '') {
            $type = TitleType::tryFrom($this->typeFilter);
            if ($type) {
                $query->type($type);
            }
        }

        if ($this->loanedFilter === 'true') {
            $query->loaned(true);
        } elseif ($this->loanedFilter === 'false') {
            $query->loaned(false);
        }

        if ($this->plexOnly) {
            $query->inPlexOnly();
        }

        if ($this->search !== '') {
            $query->search($this->search);
        }

        $sortParts = explode(':', $this->sort);
        $sortBy = $sortParts[0] ?? 'title';
        $direction = $sortParts[1] ?? 'asc';

        $query->sort($sortBy, $direction);

        return $query->paginate();
    }

    #[Computed]
    public function totals(): array
    {
        $query = app(CollectionQuery::class);

        if ($this->formatFilter !== '') {
            $format = CollectionFormat::tryFrom($this->formatFilter);
            if ($format) {
                $query->format($format);
            }
        }

        if ($this->typeFilter !== '') {
            $type = TitleType::tryFrom($this->typeFilter);
            if ($type) {
                $query->type($type);
            }
        }

        if ($this->loanedFilter === 'true') {
            $query->loaned(true);
        } elseif ($this->loanedFilter === 'false') {
            $query->loaned(false);
        }

        if ($this->plexOnly) {
            $query->inPlexOnly();
        }

        if ($this->search !== '') {
            $query->search($this->search);
        }

        return $query->totals();
    }

    #[Computed]
    public function ownershipSummaries(): array
    {
        return app(Ownership::class)->forTitles($this->titles->items());
    }

    public function clearFilters(): void
    {
        $this->reset(['formatFilter', 'typeFilter', 'loanedFilter', 'plexOnly', 'search']);
        $this->resetPage();
    }

    public function updatedFormatFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedLoanedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPlexOnly(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }
}; ?>

<div class="flex flex-col gap-6 py-6">
    <x-media.section-header :heading="__('Collection')" :subheading="__('Your owned titles from physical media, digital purchases, and Plex.')">
        <x-slot:actions>
            <flux:button :href="route('settings.features.collection')" wire:navigate variant="filled" icon="cog-6-tooth">
                {{ __('Settings') }}
            </flux:button>
        </x-slot:actions>
    </x-media.section-header>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-2">
            <flux:select wire:model.live="formatFilter" class="w-auto">
                <option value="">{{ __('All formats') }}</option>
                @foreach (\App\Enums\CollectionFormat::cases() as $format)
                    <option value="{{ $format->value }}">{{ $format->label() }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="typeFilter" class="w-auto">
                <option value="">{{ __('Movies & Shows') }}</option>
                <option value="{{ \App\Enums\TitleType::Movie->value }}">{{ __('Movies') }}</option>
                <option value="{{ \App\Enums\TitleType::Show->value }}">{{ __('TV Shows') }}</option>
            </flux:select>

            <flux:select wire:model.live="loanedFilter" class="w-auto">
                <option value="">{{ __('All items') }}</option>
                <option value="false">{{ __('Not loaned') }}</option>
                <option value="true">{{ __('Loaned out') }}</option>
            </flux:select>

            <label class="flex items-center gap-2 text-sm text-ink-muted">
                <flux:checkbox wire:model.live="plexOnly" />
                {{ __('Plex only') }}
            </label>

            @if ($formatFilter !== '' || $typeFilter !== '' || $loanedFilter !== '' || $plexOnly || $search !== '')
                <flux:button wire:click="clearFilters" variant="subtle" size="sm" icon="x-mark">
                    {{ __('Clear filters') }}
                </flux:button>
            @endif
        </div>

        <flux:select wire:model.live="sort" class="w-auto">
            <option value="title:asc">{{ __('Title (A-Z)') }}</option>
            <option value="title:desc">{{ __('Title (Z-A)') }}</option>
            <option value="acquired_at:desc">{{ __('Recently acquired') }}</option>
            <option value="acquired_at:asc">{{ __('Oldest acquired') }}</option>
            <option value="created_at:desc">{{ __('Recently added') }}</option>
            <option value="created_at:asc">{{ __('Oldest added') }}</option>
        </flux:select>
    </div>

    <div class="relative max-w-xl">
        <flux:input
            wire:model.live.debounce.400ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search titles…')"
        />
    </div>

    @php($totals = $this->totals)
    @if ($totals['titles'] > 0)
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-ink-muted">
            <span>
                <strong class="font-semibold text-ink">{{ $totals['titles'] }}</strong>
                {{ trans_choice(':count title|:count titles', $totals['titles'], ['count' => '']) }}
            </span>
            <span>{{ $totals['movies'] }} {{ \Illuminate\Support\Str::plural('movie', $totals['movies']) }}</span>
            <span>{{ $totals['shows'] }} {{ \Illuminate\Support\Str::plural('show', $totals['shows']) }}</span>
            <span>
                <strong class="font-semibold text-ink">{{ $totals['copies'] }}</strong>
                {{ trans_choice(':count copy|:count copies', $totals['copies'], ['count' => '']) }}
            </span>
            @if ($totals['loaned'] > 0)
                <span class="text-status-warning">{{ $totals['loaned'] }} {{ __('loaned out') }}</span>
            @endif
        </div>
    @endif

    @php($titles = $this->titles)
    @if ($titles->isEmpty())
        <x-media.empty-state icon="square-3-stack-3d" :heading="__('No titles in your collection')">
            @if ($formatFilter !== '' || $typeFilter !== '' || $loanedFilter !== '' || $plexOnly || $search !== '')
                {{ __('No titles match your filters.') }}
            @else
                {{ __('Start building your collection by adding copies to titles.') }}

                <div class="mt-4 flex gap-2">
                    <flux:button :href="route('search')" wire:navigate variant="primary" icon="magnifying-glass">
                        {{ __('Search titles') }}
                    </flux:button>

                    <flux:button :href="route('settings.features.collection')" wire:navigate variant="filled">
                        {{ __('Import CSV') }}
                    </flux:button>
                </div>
            @endif
        </x-media.empty-state>
    @else
        <x-media.poster-grid>
            @foreach ($titles as $title)
                @php($summary = $this->ownershipSummaries[$title->id] ?? null)

                <x-media.poster-card
                    wire:key="collection-{{ $title->id }}"
                    :image="$title->posterUrl('w342')"
                    :name="$title->name"
                    :href="route('titles.show', $title)"
                    :status="$title->libraryStatus?->state->value"
                    :type="$title->type->value"
                    :plexAvailable="$title->plexItem?->found() ?? false"
                >
                    @if ($summary && $summary->isOwned())
                        <x-slot:badge>
                            <x-media.owned-badge :summary="$summary" />
                        </x-slot:badge>
                    @endif

                    <x-slot:meta>
                        <x-media.chip>
                            {{ $title->type === \App\Enums\TitleType::Movie ? __('Movie') : __('TV Show') }}
                        </x-media.chip>
                        @if ($title->release_date)
                            {{ $title->release_date->format('Y') }}
                        @endif
                    </x-slot:meta>
                </x-media.poster-card>
            @endforeach
        </x-media.poster-grid>

        <div class="mt-4">
            {{ $titles->links() }}
        </div>
    @endif
</div>
