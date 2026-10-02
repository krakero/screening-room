<?php

use App\Actions\Plays\LogPlay;
use App\Enums\WatchedAt;
use App\Livewire\Concerns\HasCustomWatchedAt;
use App\Models\Episode;
use App\Models\MediaList;
use App\Models\Title;
use App\Services\Collection\Ownership;
use App\Services\ShowProgressData;
use App\Services\UpNext\UpNextCache;
use App\Support\CustomWatchedAtTrigger;
use App\Support\DisplayTimezone;
use App\Support\Greeting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title as PageTitle;
use Livewire\Component;

new #[PageTitle('Up Next')] class extends Component
{
    use HasCustomWatchedAt;

    #[On('episode-watched-changed')]
    public function refreshEpisodeLists(): void
    {
        unset($this->continueWatching, $this->airingThisWeek, $this->airingThisWeekByDay);
    }

    /**
     * @return Collection<int, array{title: Title, progress: ShowProgressData}>
     */
    #[Computed]
    public function continueWatching(): Collection
    {
        return app(UpNextCache::class)->continueWatching();
    }

    /**
     * @return Collection<int, Episode>
     */
    #[Computed]
    public function airingThisWeek(): Collection
    {
        return app(UpNextCache::class)->airingThisWeek();
    }

    /**
     * @return Collection<string, Collection<int, Episode>>
     */
    #[Computed]
    public function airingThisWeekByDay(): Collection
    {
        return app(UpNextCache::class)->airingThisWeekByDay();
    }

    public function dayLabel(CarbonInterface $date): string
    {
        $today = DisplayTimezone::today();

        return match (true) {
            $date->isSameDay($today) => __('Today'),
            $date->isSameDay($today->clone()->addDay()) => __('Tomorrow'),
            default => $date->format('D'),
        };
    }

    /**
     * @return Collection<int, Title>
     */
    #[Computed]
    public function recentlyWatchlisted(): Collection
    {
        return app(UpNextCache::class)->recentlyWatchlisted();
    }

    #[Computed]
    public function watchlist(): ?MediaList
    {
        return MediaList::query()->where('is_watchlist', true)->first();
    }

    /**
     * Read-only: reflects cached plex_items rows only, never a live Plex call during list rendering.
     */
    #[Computed]
    public function plexConfigured(): bool
    {
        return app(UpNextCache::class)->plexConfigured();
    }

    #[Computed]
    public function greeting(): string
    {
        return Greeting::current();
    }

    #[Computed]
    public function isEmpty(): bool
    {
        return $this->continueWatching->isEmpty()
            && $this->airingThisWeek->isEmpty()
            && $this->recentlyWatchlisted->isEmpty();
    }

    #[Computed]
    public function ownershipSummaries(): array
    {
        return app(Ownership::class)->forTitles($this->recentlyWatchlisted);
    }

    public function markWatched(int $episodeId, string $when = 'now'): void
    {
        $this->logWatched($episodeId, WatchedAt::from($when));
    }

    public function confirmCustomDatetime(string $customDatetimeTarget, string $customDatetime): void
    {
        $customDatetime = $this->resolveCustomDatetimeUtc($customDatetime);

        $this->logWatched((int) $customDatetimeTarget, WatchedAt::Custom, $customDatetime);

        Flux::modal('custom-watched-at')->close();
    }

    private function logWatched(int $episodeId, WatchedAt $when, ?CarbonImmutable $customDatetime = null): void
    {
        $episode = Episode::query()->with('title.follow', 'plays')->findOrFail($episodeId);
        $since = $episode->title->follow?->progressSince();

        if (! \App\Support\WatchedSince::watched($episode->plays, $since)) {
            app(LogPlay::class)->handle($episode, $when, $customDatetime);
        }

        unset($this->continueWatching, $this->airingThisWeek, $this->airingThisWeekByDay);
    }
}; ?>

<div class="flex flex-1 flex-col gap-10 pb-10" x-data="{ customDatetimeTarget: '', customDatetime: '' }">
    <div>
        <flux:heading size="xl">{{ $this->greeting }}</flux:heading>
        <flux:subheading>{{ __("Here's what's waiting for you.") }}</flux:subheading>
    </div>

    @if ($this->isEmpty)
        <x-media.empty-state icon="film" :heading="__('Nothing here yet')">
            {{ __('Search for a movie or show to add it to your library and start tracking what you watch.') }}

            <div class="mt-4">
                <flux:button :href="route('search')" wire:navigate variant="primary" icon="magnifying-glass">
                    {{ __('Search') }}
                </flux:button>
            </div>
        </x-media.empty-state>
    @else
        @if ($this->continueWatching->isNotEmpty())
            <x-media.poster-row :heading="__('Continue Watching')" :subheading="__('Pick up right where you left off')">
                @foreach ($this->continueWatching as $entry)
                    @php
                        $entryTitle = $entry['title'];
                        $entryProgress = $entry['progress'];
                        $entryEpisode = $entryProgress->nextEpisode;

                        $entryOnWatchedReleaseDate = sprintf("markWatched(%d, 'release_date')", $entryEpisode->id);
                        $entryOnWatchedUnknown = sprintf("markWatched(%d, 'unknown')", $entryEpisode->id);
                        $entryOnPickDatetime = CustomWatchedAtTrigger::open('custom-watched-at', (string) $entryEpisode->id);
                    @endphp

                    <x-media.episode-card
                        size="sm"
                        :image="$entryEpisode->stillUrl('w300') ?? $entryTitle->backdropUrl('w300')"
                        :poster="$entryTitle->posterUrl('w185')"
                        :name="$entryTitle->name"
                        :code="sprintf('S%02dE%02d', $entryEpisode->season_number, $entryEpisode->episode_number)"
                        :episode-name="$entryEpisode->name"
                        :episode-id="$entryEpisode->id"
                        :href="request()->fullUrlWithQuery(['episode' => $entryEpisode->id])"
                        :can-mark-watched="true"
                        :on-mark-watched="'markWatched('.$entryEpisode->id.')'"
                        :on-watched-release-date="$entryOnWatchedReleaseDate"
                        :on-watched-unknown="$entryOnWatchedUnknown"
                        :on-pick-datetime="$entryOnPickDatetime"
                        :on-pick-datetime-target="(string) $entryEpisode->id"
                        :plex-available="$this->plexConfigured && ($entryEpisode->plexItem?->found() ?? false)"
                        :plex-url="$this->plexConfigured ? $entryEpisode->plexItem?->playUrl() : null"
                        :season-href="route('titles.seasons.show', [$entryTitle, $entryEpisode->season_number])"
                        :show-href="route('titles.show', $entryTitle)"
                        :optimistic-watched="true"
                        wire:key="continue-{{ $entryEpisode->id }}"
                    />
                @endforeach
            </x-media.poster-row>
        @endif

        @if ($this->airingThisWeek->isNotEmpty())
            <x-media.poster-row :heading="__('Airing This Week')" :subheading="__('New episodes from shows you follow')" :href="route('calendar')">
                @foreach ($this->airingThisWeekByDay as $dayEpisodes)
                    @if (! $loop->first)
                        <div class="w-px shrink-0 self-stretch bg-line" aria-hidden="true"></div>
                    @endif

                    <div class="flex shrink-0 flex-col gap-3">
                        <span class="text-xs text-ink-subtle">{{ $this->dayLabel($dayEpisodes->first()->air_date) }}</span>

                        <div class="flex gap-4">
                            @foreach ($dayEpisodes as $episode)
                                @php
                                    $airingOnWatchedReleaseDate = sprintf("markWatched(%d, 'release_date')", $episode->id);
                                    $airingOnWatchedUnknown = sprintf("markWatched(%d, 'unknown')", $episode->id);
                                    $airingOnPickDatetime = CustomWatchedAtTrigger::open('custom-watched-at', (string) $episode->id);
                                @endphp

                                <x-media.episode-card
                                    size="sm"
                                    :image="$episode->stillUrl('w300') ?? $episode->title->backdropUrl('w300')"
                                    :poster="$episode->title->posterUrl('w185')"
                                    :name="$episode->title->name"
                                    :code="sprintf('S%02dE%02d', $episode->season_number, $episode->episode_number)"
                                    :episode-name="$episode->name"
                                    :episode-id="$episode->id"
                                    :date="$episode->air_date->format('D, M j')"
                                    :href="request()->fullUrlWithQuery(['episode' => $episode->id])"
                                    :can-mark-watched="$episode->hasAired()"
                                    :on-mark-watched="'markWatched('.$episode->id.')'"
                                    :on-watched-release-date="$airingOnWatchedReleaseDate"
                                    :on-watched-unknown="$airingOnWatchedUnknown"
                                    :on-pick-datetime="$airingOnPickDatetime"
                                    :on-pick-datetime-target="(string) $episode->id"
                                    :season-href="route('titles.seasons.show', [$episode->title, $episode->season_number])"
                                    :show-href="route('titles.show', $episode->title)"
                                    :optimistic-watched="true"
                                    wire:key="airing-{{ $episode->id }}"
                                />
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </x-media.poster-row>
        @endif

        @if ($this->recentlyWatchlisted->isNotEmpty())
            <x-media.poster-row :heading="__('Recently Added to Watchlist')" :href="$this->watchlist ? route('lists.show', $this->watchlist) : null">
                @foreach ($this->recentlyWatchlisted as $recentTitle)
                    @php($summary = $this->ownershipSummaries[$recentTitle->id] ?? null)

                    <x-media.poster-card
                        size="sm"
                        class="!w-40 sm:!w-44"
                        :image="$recentTitle->posterUrl('w342')"
                        :name="$recentTitle->name"
                        :subtitle="$recentTitle->isMovie() ? __('Movie') : __('Show')"
                        :href="route('titles.show', $recentTitle)"
                        :type="$recentTitle->type->value"
                        :plex-available="$this->plexConfigured && ($recentTitle->plexItem?->found() ?? false)"
                        wire:key="watchlisted-{{ $recentTitle->id }}"
                    >
                        @if ($summary && $summary->isOwned())
                            <x-slot:badge>
                                <x-media.owned-badge :summary="$summary" />
                            </x-slot:badge>
                        @endif
                    </x-media.poster-card>
                @endforeach
            </x-media.poster-row>
        @endif
    @endif

    <flux:modal name="custom-watched-at" class="md:w-96">
        <form
            x-on:submit.prevent="
                $flux.modal('custom-watched-at').close();
                window.dispatchEvent(new CustomEvent('watched-custom-flip', { detail: { target: customDatetimeTarget } }));
                $wire.confirmCustomDatetime(customDatetimeTarget, customDatetime).catch(() => {
                    window.dispatchEvent(new CustomEvent('watched-custom-flip-revert', { detail: { target: customDatetimeTarget } }));
                    window.dispatchEvent(new CustomEvent('toast-show', { detail: { variant: 'danger', text: @js(__('That didn’t save — please try again.')) } }));
                });
            "
            class="space-y-6"
        >
            <div>
                <flux:heading size="lg">{{ __('Pick a date & time') }}</flux:heading>
            </div>

            <flux:input type="datetime-local" x-model="customDatetime" name="customDatetime" :label="__('Watched at')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
