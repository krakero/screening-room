<?php

use App\Actions\Plays\LogPlay;
use App\Actions\Plays\MarkSeasonWatched;
use App\Actions\Plays\RemovePlay;
use App\Actions\Tmdb\EnsureSeasonEpisodes;
use App\Enums\PlaySource;
use App\Enums\WatchedAt;
use App\Jobs\ImportSeasonEpisodes;
use App\Jobs\RefreshSeasonTrailer;
use App\Livewire\Concerns\HasCustomWatchedAt;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Services\Seasons\SeasonOverview;
use App\Support\CustomWatchedAtTrigger;
use App\Support\WatchedSince;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new #[Layout('layouts::app', ['fullBleed' => true])] class extends Component
{
    use HasCustomWatchedAt;

    #[On('episode-watched-changed')]
    public function refreshEpisodes(): void
    {
        unset($this->episodes, $this->plexPlayUrls, $this->airedUnwatchedCount, $this->nextEpisode);
    }

    public Title $title;

    public Season $season;

    public string $pendingBulkWhen = 'now';

    public bool $trailerOpen = false;

    public bool $awaitingTrailer = false;

    public ?int $awaitingTrailerDispatchedAt = null;

    public ?int $awaitingPlexDispatchedAt = null;

    public function mount(Title $title, int $seasonNumber): void
    {
        $this->title = $title;
        $this->title->loadMissing('follow');
        $this->season = $title->seasons()->where('season_number', $seasonNumber)->firstOrFail();

        $this->refreshTrailerIfStale();
        $this->refreshPlexIfPending();
    }

    /**
     * Queue a trailer fetch when the season has no trailer and hasn't been checked in the
     * last 30 days. Dispatched onto the queue so the page never blocks on TMDB;
     * `RefreshSeasonTrailer` is `ShouldBeUnique` per season, guarding against duplicate
     * concurrent fetches.
     */
    private function refreshTrailerIfStale(): void
    {
        if (filled($this->season->trailer_key)) {
            return;
        }

        $checkedAt = $this->season->trailer_checked_at;

        if ($checkedAt === null || $checkedAt->lt(now()->subDays(30))) {
            RefreshSeasonTrailer::dispatch($this->season);
            $this->awaitingTrailer = true;
            $this->awaitingTrailerDispatchedAt = now()->timestamp;
        }
    }

    /**
     * Polled via `wire:poll.3s` only while a trailer lookup is in flight; re-reads the
     * season and clears the flag once the lookup has been stamped, or after ~30s regardless
     * (so a stuck queue never polls forever).
     */
    public function checkTrailer(): void
    {
        if (! $this->awaitingTrailer) {
            return;
        }

        $this->season->refresh();

        $stamped = $this->season->trailer_checked_at !== null
            && $this->season->trailer_checked_at->timestamp >= $this->awaitingTrailerDispatchedAt;

        if ($stamped || now()->timestamp - $this->awaitingTrailerDispatchedAt >= 30) {
            $this->awaitingTrailer = false;
        }
    }

    /**
     * `SeasonOverview::plexPlayUrls()` (below) reads cached Plex availability only — it never
     * calls Plex during render. Episodes with no `plex_items` row yet each queue a background
     * resolve job; this records when so the view can poll for it, same pattern as the trailer
     * flag above.
     */
    private function refreshPlexIfPending(): void
    {
        if (app(SeasonOverview::class)->hasPendingPlex($this->title, $this->episodes)) {
            $this->awaitingPlexDispatchedAt = now()->timestamp;
        }
    }

    /**
     * Polled via `wire:poll.3s` only while at least one episode's Plex lookup is in flight. A
     * cheap count query each tick (no Plex call); only once every aired episode has resolved (or
     * after ~30s) does it re-fetch the play URLs and force a real render — `#[Renderless]` skips
     * every tick in between.
     */
    #[Renderless]
    public function checkPlex(): void
    {
        if ($this->awaitingPlexDispatchedAt === null) {
            return;
        }

        $resolved = ! app(SeasonOverview::class)->hasPendingPlex($this->title, $this->episodes);
        $timedOut = now()->timestamp - $this->awaitingPlexDispatchedAt >= 30;

        if (! $resolved && ! $timedOut) {
            return;
        }

        $this->awaitingPlexDispatchedAt = null;
        unset($this->plexPlayUrls);
        $this->forceRender();
    }

    public function openTrailer(): void
    {
        $this->trailerOpen = true;

        Flux::modal('watch-trailer')->show();
    }

    /**
     * Force-refreshes this season's episodes from TMDB regardless of staleness. Queued (never
     * runs inline), so the header button just confirms it was dispatched with a toast.
     */
    public function refreshSeason(): void
    {
        ImportSeasonEpisodes::dispatch($this->title->id, $this->season->season_number);
    }

    public function closeTrailer(): void
    {
        $this->trailerOpen = false;
    }

    public function render()
    {
        return $this->view()->title(__(':title — :season', [
            'title' => $this->title->name,
            'season' => $this->seasonLabel(),
        ]));
    }

    public function loadEpisodes(EnsureSeasonEpisodes $ensureSeasonEpisodes): void
    {
        if (! $this->season->episodesLoaded()) {
            $this->season = $ensureSeasonEpisodes->handle($this->season);
        }
    }

    /**
     * @return Collection<int, Episode>
     */
    #[Computed]
    public function episodes(): Collection
    {
        return $this->season->episodes()->with('plays')->get();
    }

    /**
     * Plex "play" links for this season's aired episodes, keyed by episode id. Unaired episodes
     * are skipped (they can't be on the server yet) and missing/unconfigured items are omitted.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function plexPlayUrls(): array
    {
        return app(SeasonOverview::class)->plexPlayUrls($this->title, $this->episodes);
    }

    /**
     * While the show is rewatching, watched checks below count only plays since this date.
     */
    #[Computed]
    public function progressSince(): ?CarbonInterface
    {
        return $this->title->follow?->progressSince();
    }

    /**
     * Aired episodes in this season that haven't been marked watched — the count shown in the
     * "mark season watched" confirmation.
     */
    #[Computed]
    public function airedUnwatchedCount(): int
    {
        return app(SeasonOverview::class)->airedUnwatchedCount($this->episodes, $this->progressSince);
    }

    #[Computed]
    public function nextEpisode(): ?Episode
    {
        return app(SeasonOverview::class)->nextEpisode($this->episodes, $this->progressSince);
    }

    #[Computed]
    public function previousSeason(): ?Season
    {
        return $this->title->seasons()
            ->where('season_number', '<', $this->season->season_number)
            ->orderByDesc('season_number')
            ->first();
    }

    #[Computed]
    public function nextSeason(): ?Season
    {
        return $this->title->seasons()
            ->where('season_number', '>', $this->season->season_number)
            ->orderBy('season_number')
            ->first();
    }

    public function seasonLabel(): string
    {
        return $this->season->season_number === 0
            ? __('Specials')
            : __('Season :number', ['number' => $this->season->season_number]);
    }

    public function markSeasonWatched(string $when = 'now'): void
    {
        $this->applyWatched('season', null, WatchedAt::from($when));
    }

    public function confirmMarkSeasonWatched(string $when = 'now'): void
    {
        $this->pendingBulkWhen = $when;

        Flux::modal('confirm-season-watched')->show();
    }

    #[Renderless]
    public function confirmedMarkSeasonWatched(): void
    {
        $this->markSeasonWatched($this->pendingBulkWhen);

        Flux::modal('confirm-season-watched')->close();
    }

    #[Renderless]
    public function toggleEpisode(int $episodeId, string $when = 'now'): void
    {
        $episode = $this->season->episodes()->findOrFail($episodeId);
        $countedPlays = $this->countedPlays($episode);

        $play = $countedPlays->firstWhere('source', PlaySource::Manual) ?? $countedPlays->sortByDesc('watched_at')->first();

        if ($play) {
            app(RemovePlay::class)->handle($play);

            return;
        }

        $this->applyWatched('episode', $episodeId, WatchedAt::from($when));
    }

    /**
     * "Watch again" on an already-watched episode card: always adds a new play (never toggles
     * off), same LogPlay path as every other watch action on this page.
     */
    public function watchAgain(int $episodeId): void
    {
        $this->applyWatched('episode', $episodeId, WatchedAt::Now);
    }

    public function confirmCustomDatetime(string $customDatetimeTarget, string $customDatetime): void
    {
        $customDatetime = $this->resolveCustomDatetimeUtc($customDatetime);

        [$target, $id] = array_pad(explode(':', $customDatetimeTarget, 2), 2, null);

        $this->applyWatched($target, $id !== null ? (int) $id : null, WatchedAt::Custom, $customDatetime);

        Flux::modal('custom-watched-at')->close();
    }

    /**
     * The episode's plays that count toward "watched" right now — while rewatching, only plays
     * since the restart (see WatchedSince); all its plays otherwise. Used to pick which play
     * "unmark watched" removes, so it matches the watched checkmark shown for the episode.
     *
     * @return Collection<int, Play>
     */
    private function countedPlays(Episode $episode): Collection
    {
        $since = $this->progressSince;
        $query = $episode->plays();

        if ($since !== null) {
            $query->where(function ($query) use ($since): void {
                $query->where('watched_at', '>=', $since)
                    ->orWhere(fn ($query) => $query->whereNull('watched_at')->where('created_at', '>=', $since));
            });
        }

        return $query->get();
    }

    private function applyWatched(string $target, ?int $episodeId, WatchedAt $when, ?CarbonImmutable $customDatetime = null): void
    {
        match ($target) {
            'season' => app(MarkSeasonWatched::class)->handle($this->season, $when, $customDatetime),
            'episode' => app(LogPlay::class)->handle($this->season->episodes()->findOrFail($episodeId), $when, $customDatetime),
        };
    }
}; ?>

<div class="flex flex-1 flex-col pb-16" @if (! $season->episodesLoaded()) wire:init="loadEpisodes" @endif x-data="{ customDatetimeTarget: '', customDatetime: '' }">
    <x-media.hero :backdrop="$title->backdropUrl('w1280')" :poster="$season->posterUrl() ?? $title->posterUrl()">
        <x-slot:title>
            <a href="{{ route('titles.show', $title) }}" wire:navigate class="block text-sm font-medium text-ink-muted hover:text-ink">
                &larr; {{ $title->name }}
            </a>
            {{ $this->seasonLabel() }}
        </x-slot:title>

        <x-slot:meta>
            @if ($season->air_date)
                <x-media.chip>{{ $season->air_date->format('Y') }}</x-media.chip>
            @endif

            <x-media.chip>{{ __(':count episodes', ['count' => $season->episode_count ?? $this->episodes->count()]) }}</x-media.chip>
        </x-slot:meta>

        <x-slot:actions>
            @if ($season->hasTrailer())
                <flux:button size="sm" variant="filled" icon="play-circle" wire:click="openTrailer">
                    {{ __('Watch trailer') }}
                </flux:button>
            @elseif ($awaitingTrailer)
                <flux:button size="sm" variant="ghost" icon="arrow-path" icon:class="animate-spin" disabled wire:poll.3s="checkTrailer">
                    {{ __('Checking for trailer…') }}
                </flux:button>
            @endif

            @if ($awaitingPlexDispatchedAt !== null)
                <flux:button size="sm" variant="ghost" icon="arrow-path" icon:class="animate-spin" disabled wire:poll.3s="checkPlex">
                    {{ __('Checking Plex…') }}
                </flux:button>
            @endif

            @if ($season->season_number !== 0)
                <x-media.watched-split-button
                    action="confirmMarkSeasonWatched"
                    custom-target="season"
                    size="sm"
                    :label="__('Mark season watched')"
                />
            @endif

            <flux:button
                size="sm"
                variant="ghost"
                icon="arrow-path"
                aria-label="{{ __('Refresh season') }}"
                x-on:click="
                    $wire.refreshSeason()
                        .then(() => window.dispatchEvent(new CustomEvent('toast-show', { detail: { text: @js(__('Refresh queued.')) } })))
                        .catch(() => window.dispatchEvent(new CustomEvent('toast-show', { detail: { variant: 'danger', text: @js(__('That didn’t save — please try again.')) } })))
                "
            >
                {{ __('Refresh season') }}
            </flux:button>
        </x-slot:actions>
    </x-media.hero>

    <x-page-container class="flex flex-col gap-8 pt-8">
        @if ($season->overview)
            <p class="max-w-3xl text-sm leading-relaxed text-ink-muted">{{ $season->overview }}</p>
        @endif

        <livewire:collection-copies :title="$title" :season="$season" :key="'collection-copies-season-'.$season->id" />

        <section class="flex flex-col gap-3">
            <x-media.section-header :heading="__('Episodes')" />

            @if (! $season->episodesLoaded())
                <div class="flex flex-col gap-1">
                    @for ($i = 0; $i < 3; $i++)
                        <div class="flex items-center gap-4 rounded-lg p-2">
                            <div class="aspect-16/9 w-32 shrink-0 animate-pulse rounded-md bg-surface-raised sm:w-40"></div>
                            <div class="flex flex-1 flex-col gap-2">
                                <div class="h-3 w-24 animate-pulse rounded bg-surface-raised"></div>
                                <div class="h-4 w-48 animate-pulse rounded bg-surface-raised"></div>
                            </div>
                        </div>
                    @endfor

                    <p class="px-2 py-1 text-sm text-ink-subtle">{{ __('Loading episodes…') }}</p>
                </div>
            @else
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($this->episodes as $episode)
                        @php
                            $watched = WatchedSince::watched($episode->plays, $this->progressSince);
                            $hasManualPlay = $episode->plays->contains(fn ($play) => $play->source === PlaySource::Manual);
                            $isNextEpisode = $this->nextEpisode?->is($episode) ?? false;
                            $code = sprintf('S%02dE%02d', $episode->season_number, $episode->episode_number);
                            $plexUrl = $this->plexPlayUrls[$episode->id] ?? null;

                            $onMarkWatched = 'toggleEpisode('.$episode->id.')';
                            $onWatchedReleaseDate = sprintf("toggleEpisode(%d, 'release_date')", $episode->id);
                            $onWatchedUnknown = sprintf("toggleEpisode(%d, 'unknown')", $episode->id);
                            $onPickDatetime = CustomWatchedAtTrigger::open('custom-watched-at', 'episode:'.$episode->id);
                        @endphp

                        <x-media.episode-card
                            wire:key="episode-{{ $episode->id }}"
                            :image="$episode->stillUrl()"
                            :name="$episode->name"
                            :code="$code"
                            :date="$episode->air_date?->format('M j, Y')"
                            :episode-id="$episode->id"
                            :href="request()->fullUrlWithQuery(['episode' => $episode->id])"
                            :watched="$watched"
                            :can-mark-watched="! $watched && $episode->hasAired()"
                            :on-mark-watched="$onMarkWatched"
                            :on-watched-release-date="$onWatchedReleaseDate"
                            :on-watched-unknown="$onWatchedUnknown"
                            :on-pick-datetime="$onPickDatetime"
                            :on-pick-datetime-target="'episode:'.$episode->id"
                            :on-unmark-watched="$onMarkWatched"
                            :unmark-confirm="$watched && ! $hasManualPlay ? __('This episode was watched via Plex/Trakt, not logged manually here. Remove it anyway?') : null"
                            :on-watch-again="'watchAgain('.$episode->id.')'"
                            :plex-available="$plexUrl !== null"
                            :plex-url="$plexUrl"
                            :muted="! $episode->hasAired()"
                            optimistic-watched
                            optimistic-bulk-event="mark-season-watched"
                            @class([
                                'ring-1 ring-inset ring-accent/30' => $isNextEpisode,
                            ])
                        >
                            @if ($episode->isSpecial() || $isNextEpisode)
                                <x-slot:chip>
                                    <div class="flex flex-wrap items-center gap-1">
                                        @if ($isNextEpisode)
                                            <x-media.badge tone="accent" variant="solid">{{ __('Up Next') }}</x-media.badge>
                                        @endif

                                        @if ($episode->isSpecial())
                                            <x-media.chip>{{ __('Special') }}</x-media.chip>
                                        @endif
                                    </div>
                                </x-slot:chip>
                            @endif
                        </x-media.episode-card>
                    @endforeach
                </div>
            @endif
        </section>

        <nav class="flex items-center justify-between gap-4 border-t border-line pt-6">
            @if ($this->previousSeason)
                <a href="{{ route('titles.seasons.show', [$title, $this->previousSeason->season_number]) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">
                    &larr; {{ $this->previousSeason->season_number === 0 ? __('Specials') : __('Season :number', ['number' => $this->previousSeason->season_number]) }}
                </a>
            @else
                <span></span>
            @endif

            @if ($this->nextSeason)
                <a href="{{ route('titles.seasons.show', [$title, $this->nextSeason->season_number]) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">
                    {{ $this->nextSeason->season_number === 0 ? __('Specials') : __('Season :number', ['number' => $this->nextSeason->season_number]) }} &rarr;
                </a>
            @endif
        </nav>
    </x-page-container>

    <x-media.trailer-modal
        name="watch-trailer"
        :open="$trailerOpen"
        :embed-url="$season->trailerEmbedUrl()"
        :watch-url="$season->trailerWatchUrl()"
        :site="$season->trailer_site"
        on-close="closeTrailer"
    />

    <flux:modal name="custom-watched-at" class="md:w-96">
        <form
            x-on:submit.prevent="
                $flux.modal('custom-watched-at').close();
                if (customDatetimeTarget === 'season') {
                    $dispatch('mark-season-watched');
                } else {
                    window.dispatchEvent(new CustomEvent('watched-custom-flip', { detail: { target: customDatetimeTarget } }));
                }
                $wire.confirmCustomDatetime(customDatetimeTarget, customDatetime).catch(() => {
                    if (customDatetimeTarget !== 'season') {
                        window.dispatchEvent(new CustomEvent('watched-custom-flip-revert', { detail: { target: customDatetimeTarget } }));
                    }
                    window.dispatchEvent(new CustomEvent('toast-show', { detail: { variant: 'danger', text: @js(__('That didn’t save — please try again.')) } }));
                });
            "
            class="space-y-6"
        >
            <div>
                <flux:heading size="lg">{{ __('Pick a date & time') }}</flux:heading>

                <template x-if="customDatetimeTarget === 'season'">
                    <flux:text class="mt-2">
                        {{ __('This will mark all :count aired episodes of :season as watched.', ['count' => $this->airedUnwatchedCount, 'season' => $this->seasonLabel()]) }}
                    </flux:text>
                </template>
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

    <flux:modal name="confirm-season-watched" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Mark season watched?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Mark all :count aired episodes of :season as watched?', ['count' => $this->airedUnwatchedCount, 'season' => $this->seasonLabel()]) }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" x-on:click="$dispatch('mark-season-watched')" wire:click="confirmedMarkSeasonWatched">
                    {{ __('Mark season watched') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
