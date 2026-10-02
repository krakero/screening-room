<?php

use App\Enums\TitleType;
use App\Models\Title;
use App\Services\Collection\Ownership;
use App\Services\Search\SearchTitles;
use App\Services\Tmdb\TmdbException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title as PageTitle;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[PageTitle('Search')] class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $query = '';

    public ?string $error = null;

    /**
     * @return array<int, array{tmdb_id: int, type: TitleType, name: string, year: ?string, poster: ?string, title: ?Title, href: string, watched: bool, status: ?string, on_list: bool, followed: bool}>
     */
    #[Computed]
    public function results(): array
    {
        $query = trim($this->query);

        if ($query === '') {
            return [];
        }

        try {
            $results = app(SearchTitles::class)->handle($query);
        } catch (TmdbException) {
            $this->error = __('Search failed. Please try again in a moment.');

            return [];
        }

        $this->error = null;

        return $results->map(function (array $result): array {
            $title = $result['title'];

            return [
                'tmdb_id' => $result['tmdb_id'],
                'type' => $result['type'],
                'name' => $result['name'],
                'year' => $result['year'],
                'poster' => $result['poster'],
                'title' => $title,
                'href' => $title
                    ? route('titles.show', $title)
                    : route('titles.tmdb', array_filter([
                        'type' => $result['type']->value,
                        'tmdbId' => $result['tmdb_id'],
                        'name' => $result['name'],
                        'poster' => $result['poster'],
                        'backdrop' => $result['backdrop'],
                        'year' => $result['year'],
                    ], fn (mixed $value): bool => $value !== null)),
                'watched' => $result['watched'],
                'status' => $title?->libraryStatus?->state->value,
                'on_list' => (bool) ($title?->on_list ?? false),
                'followed' => ((bool) ($title?->is_followed ?? false)) && ! $result['watched'],
            ];
        })->all();
    }

    #[Computed]
    public function ownershipSummaries(): array
    {
        $titles = collect($this->results)->pluck('title')->filter();

        return app(Ownership::class)->forTitles($titles);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
    <flux:heading size="xl">{{ __('Search') }}</flux:heading>
    <flux:subheading>{{ __('Find movies and shows to add to your library.') }}</flux:subheading>

    <div class="relative max-w-xl">
        <flux:input
            wire:model.live.debounce.400ms="query"
            icon="magnifying-glass"
            :placeholder="__('Search titles…')"
            autofocus
        />
    </div>

    @php($results = $this->results)

    <div wire:loading wire:target="query" class="flex flex-col gap-4">
        <x-media.poster-grid>
            @for ($i = 0; $i < 12; $i++)
                <div class="flex flex-col gap-2">
                    <div class="aspect-2/3 w-full animate-pulse rounded-lg bg-surface-raised"></div>
                    <div class="h-3 w-3/4 animate-pulse rounded bg-surface-raised"></div>
                    <div class="h-3 w-1/2 animate-pulse rounded bg-surface-raised"></div>
                </div>
            @endfor
        </x-media.poster-grid>
    </div>

    <div wire:loading.remove wire:target="query" class="flex flex-col gap-4">
        @if ($this->error)
            <flux:callout variant="danger" icon="exclamation-triangle" :heading="$this->error" />
        @endif

        @if (trim($query) === '')
            <x-media.empty-state icon="magnifying-glass" :heading="__('Search for movies and shows')">
                {{ __('Start typing to search TMDB.') }}
            </x-media.empty-state>
        @elseif (empty($results))
            @unless ($this->error)
                <x-media.empty-state icon="film" :heading="__('No results')">
                    {{ __('No movies or shows matched ":query".', ['query' => $query]) }}
                </x-media.empty-state>
            @endunless
        @else
            <x-media.poster-grid>
                @foreach ($results as $result)
                    @php($summary = $result['title'] ? ($this->ownershipSummaries[$result['title']->id] ?? null) : null)

                    <x-media.poster-card
                        wire:key="result-{{ $result['type']->value }}-{{ $result['tmdb_id'] }}"
                        :image="$result['poster']"
                        :name="$result['name']"
                        :href="$result['href']"
                        :watched="$result['watched']"
                        :status="$result['status']"
                        :type="$result['type']->value"
                    >
                        @if ($result['on_list'] || $result['followed'] || ($summary && $summary->isOwned()))
                            <x-slot:badge>
                                @if ($summary && $summary->isOwned())
                                    <x-media.owned-badge :summary="$summary" />
                                @endif

                                @if ($result['on_list'])
                                    <x-media.badge tone="accent" icon="bookmark">{{ __('On list') }}</x-media.badge>
                                @endif

                                @if ($result['followed'])
                                    <x-media.badge tone="accent" icon="eye">{{ __('Following') }}</x-media.badge>
                                @endif
                            </x-slot:badge>
                        @endif

                        <x-slot:meta>
                            <x-media.chip>
                                {{ $result['type'] === TitleType::Movie ? __('Movie') : __('TV Show') }}
                            </x-media.chip>
                            @if ($result['year'])
                                {{ $result['year'] }}
                            @endif
                        </x-slot:meta>
                    </x-media.poster-card>
                @endforeach
            </x-media.poster-grid>
        @endif
    </div>
</div>
