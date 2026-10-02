<?php

use App\Actions\Follows\FollowShow;
use App\Actions\Follows\PauseShow;
use App\Actions\Follows\RestartShow;
use App\Actions\Follows\ResumeShow;
use App\Actions\Follows\StopRewatch;
use App\Actions\Plays\LogPlay;
use App\Actions\Plays\MarkShowWatched;
use App\Actions\Plays\RemovePlay;
use App\Actions\Ratings\ClearRatingReview;
use App\Actions\Ratings\ClearRatingScore;
use App\Actions\Ratings\SetRatingReview;
use App\Actions\Ratings\SetRatingScore;
use App\Enums\CreditType;
use App\Enums\FollowState;
use App\Enums\PlaySource;
use App\Enums\RatingSource;
use App\Enums\WatchedAt;
use App\Jobs\RefreshSeasonTrailer;
use App\Jobs\RefreshTitleFromTmdb;
use App\Jobs\RefreshTitleRatings;
use App\Jobs\RefreshTitleTrailer;
use App\Livewire\Concerns\HasCustomWatchedAt;
use App\Livewire\Concerns\HasDeferredLoad;
use App\Models\Episode;
use App\Models\ExternalRating;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Title;
use App\Services\ShowProgress;
use App\Services\ShowProgressData;
use App\Services\Titles\TitleOverview;
use App\Support\CustomWatchedAtTrigger;
use App\Support\DisplayTimezone;
use App\Support\IntegrationSettings;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new #[Layout('layouts::app', ['fullBleed' => true])] class extends Component
{
    use HasCustomWatchedAt;
    use HasDeferredLoad;

    public Title $title;

    public string $pendingBulkWhen = 'now';

    public bool $trailerOpen = false;

    public bool $seasonTrailerOpen = false;

    public string $reviewText = '';

    public bool $reviewSpoilers = false;

    public ?string $watchedOn = null;

    public function mount(Title $title): void
    {
        $this->title = $title;

        // Read-only, cheap, and correct regardless of Eloquent's lazy-loading-guard hydration
        // quirk (the guard only ever fires on multi-row hydration — a route-bound single title
        // never trips it either way, but eager-loading here means these two never rely on that).
        $this->title->loadMissing(['follow', 'rating', 'networks', 'watchProviders']);

        $this->refreshRatingsIfStale();
        $this->refreshTrailerIfStale();
        $this->refreshSeasonTrailerIfStale();
        $this->refreshPlexIfPending();
    }

    /**
     * Queue an external-ratings fetch when they're missing or more than 7 days old.
     * Dispatched onto the queue (not run inline) so the page never blocks on MDBList;
     * `RefreshTitleRatings` is `ShouldBeUnique` per title, guarding against duplicate
     * concurrent fetches from several page views landing at once.
     */
    private function refreshRatingsIfStale(): void
    {
        $checkedAt = $this->title->ratings_checked_at;

        if ($checkedAt === null || $checkedAt->lt(now()->subDays(7))) {
            RefreshTitleRatings::dispatch($this->title);

            if (app(IntegrationSettings::class)->configured('mdblist.api_key')) {
                $this->markDeferredPending('ratings');
            }
        }
    }

    /**
     * Queue a trailer fetch when the title has no trailer and hasn't been checked in the
     * last 30 days. A title that already has a trailer is left alone. Dispatched onto the
     * queue (not run inline) so the page never blocks on TMDB; `RefreshTitleTrailer` is
     * `ShouldBeUnique` per title, guarding against duplicate concurrent fetches.
     */
    private function refreshTrailerIfStale(): void
    {
        if (filled($this->title->trailer_key)) {
            return;
        }

        $checkedAt = $this->title->trailer_checked_at;

        if ($checkedAt === null || $checkedAt->lt(now()->subDays(30))) {
            $this->dispatchDeferred(
                key: 'show-trailer',
                lockKey: "title-trailer:{$this->title->id}",
                dispatch: fn () => RefreshTitleTrailer::dispatch($this->title),
            );
        }
    }

    /**
     * Same as `refreshTrailerIfStale()`, but for the current season (see `currentSeason()`),
     * so a "Season N trailer" button can appear next to the show trailer without a full reload.
     */
    private function refreshSeasonTrailerIfStale(): void
    {
        $season = $this->currentSeason;

        if ($season === null || filled($season->trailer_key)) {
            return;
        }

        $checkedAt = $season->trailer_checked_at;

        if ($checkedAt === null || $checkedAt->lt(now()->subDays(30))) {
            $this->dispatchDeferred(
                key: 'season-trailer',
                lockKey: "season-trailer:{$season->id}",
                dispatch: fn () => RefreshSeasonTrailer::dispatch($season),
            );
        }
    }

    /**
     * `TitleOverview::plexPlayUrl()` (below) reads cached Plex availability only — it never
     * calls Plex during render. Accessing it here (its result is unused) queues a background
     * resolve job as a side effect when the show's next-unwatched episode has no `plex_items`
     * row yet; this then records when so the view can poll for it, same pattern as the trailer
     * flags above. (The view only accesses `plexPlayUrl` itself in the non-awaiting branch, so
     * without this call the job would never be dispatched.)
     */
    private function refreshPlexIfPending(): void
    {
        $this->plexPlayUrl;

        if (app(TitleOverview::class)->plexPending($this->title, $this->progressForOverview())) {
            $this->markDeferredPending('plex');
        }
    }

    /**
     * Required by `HasDeferredLoad::pollDeferred()`. Only 'plex', 'show-trailer',
     * 'season-trailer' and 'ratings' are ever marked pending on this page.
     */
    protected function deferredIsResolved(string $key): bool
    {
        return match ($key) {
            'ratings' => $this->title->fresh()->ratings_checked_at?->timestamp
                >= $this->deferredPendingSince['ratings'],
            'plex' => ! app(TitleOverview::class)->plexPending($this->title),
            'show-trailer' => $this->title->fresh()->trailer_checked_at?->timestamp
                >= $this->deferredPendingSince['show-trailer'],
            'season-trailer' => ($season = $this->currentSeason) === null
                || $season->fresh()->trailer_checked_at?->timestamp >= $this->deferredPendingSince['season-trailer'],
        };
    }

    /**
     * Called once a deferred key resolves or times out, just before `pollDeferred()` forces a
     * re-render — refreshes the model(s) and computed caches that key's UI reads from.
     */
    protected function onDeferredResolved(string $key): void
    {
        if ($key === 'plex') {
            unset($this->plexPlayUrl);

            return;
        }

        if ($key === 'ratings') {
            $this->title->refresh();
            unset($this->externalRatings);

            return;
        }

        if ($key === 'show-trailer') {
            $this->title->refresh();

            return;
        }

        if ($key === 'season-trailer') {
            $this->currentSeason?->refresh();
        }
    }

    /**
     * `wire:poll.3s` target for the combined trailer indicator, which covers both the show and
     * season trailer lookups behind a single "Checking for trailer…" state.
     */
    #[Renderless]
    public function pollTrailers(): void
    {
        $this->pollDeferred('show-trailer');
        $this->pollDeferred('season-trailer');
    }

    public function render()
    {
        return $this->view()->title($this->title->name);
    }

    /**
     * @return Collection<int, \App\Models\Credit>
     */
    #[Computed]
    public function cast(): Collection
    {
        return $this->title->credits()
            ->with('person')
            ->where('type', CreditType::Cast)
            ->orderBy('order')
            ->limit(12)
            ->get();
    }

    /**
     * @return Collection<int, Play>
     */
    #[Computed]
    public function plays(): Collection
    {
        return $this->title->plays()->orderByDesc('watched_at')->get();
    }

    /**
     * @return Collection<int, ExternalRating>
     */
    #[Computed]
    public function externalRatings(): Collection
    {
        $order = array_column(RatingSource::cases(), 'value');

        return $this->title->externalRatings()->get()
            ->sortBy(fn (ExternalRating $rating) => array_search($rating->source->value, $order))
            ->values();
    }

    /**
     * Seasons in display order: regular seasons first (ascending), specials (season 0) last.
     *
     * @return Collection<int, Season>
     */
    #[Computed]
    public function orderedSeasons(): Collection
    {
        return $this->title->seasons
            ->sortBy(fn (Season $season) => $season->season_number === 0 ? PHP_INT_MAX : $season->season_number)
            ->values();
    }

    #[Computed]
    public function progress(): ShowProgressData
    {
        return app(ShowProgress::class)->for($this->title);
    }

    /**
     * The episode for the "Up next" card: the next aired-and-unwatched episode, or — once
     * caught up on everything aired — the next episode overall (unaired, or S1E1 for a show
     * that's never been watched). Null for movies or a show with nothing left at all.
     */
    /**
     * The already-computed progress to hand `TitleOverview` so it doesn't re-load every episode
     * and play per call; null for movies, which never need it.
     */
    private function progressForOverview(): ?ShowProgressData
    {
        return $this->title->isShow() ? $this->progress : null;
    }

    #[Computed]
    public function nextUpEpisode(): ?Episode
    {
        return app(TitleOverview::class)->nextUpEpisode($this->title, $this->progress->nextEpisode);
    }

    /**
     * The season to offer a "Season N trailer" button for: the season of the next unwatched
     * episode, or (once caught up / for a show with nothing unwatched) the most recently
     * aired season. Null for movies or a show with no aired seasons yet.
     */
    #[Computed]
    public function currentSeason(): ?Season
    {
        return app(TitleOverview::class)->currentSeason($this->title, $this->progressForOverview());
    }

    /**
     * The current season, only when it actually has a trailer to offer.
     */
    #[Computed]
    public function seasonTrailer(): ?Season
    {
        $season = $this->currentSeason;

        return $season?->hasTrailer() ? $season : null;
    }

    /**
     * Aired episodes across the show that haven't been marked watched — the count shown in the
     * "mark show watched" confirmation.
     */
    #[Computed]
    public function airedUnwatchedCount(): int
    {
        return $this->progress->airedCount - $this->progress->watchedCount;
    }

    /**
     * The Plex "play" link for this movie, or for a show's next unwatched episode (falling back
     * to the show itself once caught up). Null when Plex isn't configured or the item can't be found.
     */
    #[Computed]
    public function plexPlayUrl(): ?string
    {
        return app(TitleOverview::class)->plexPlayUrl($this->title, $this->progressForOverview());
    }

    /**
     * Per-season watched percentage / completeness for the season poster row, keyed by season id.
     * Falls back to the season's TMDB episode_count when its episodes aren't loaded yet.
     *
     * @return array<int, array{percent: float, complete: bool}>
     */
    #[Computed]
    public function seasonProgress(): array
    {
        return app(TitleOverview::class)->seasonProgress($this->orderedSeasons);
    }

    /**
     * The show's follow state, embedded directly into the watched-status line's label (there's
     * no separate follow-state badge any more — Follow/Pause/Resume is a single contextual row
     * in the "…" menu, below). Null for movies.
     */
    #[Computed]
    public function followState(): ?FollowState
    {
        return $this->title->follow?->state;
    }

    /**
     * Whether the show is currently mid-rewatch (drives the "Stop rewatch" menu row — there's no
     * badge for it anywhere, per the user's decision).
     */
    #[Computed]
    public function isRewatching(): bool
    {
        return $this->title->follow?->isRewatching() ?? false;
    }

    /**
     * Start following the show. Same actions the standalone follow-control component uses
     * elsewhere; inlined here (`follow()`/`pause()`/`resume()`) so the "…" menu's single
     * contextual row can `wire:click` straight into this component (Livewire resolves
     * `wire:click` against the nearest enclosing component, and this menu lives inside the
     * page, not a child component).
     */
    public function follow(): void
    {
        $this->title->setRelation('follow', app(FollowShow::class)->handle($this->title));
    }

    public function pause(): void
    {
        if ($this->title->follow === null) {
            return;
        }

        $this->title->setRelation('follow', app(PauseShow::class)->handle($this->title->follow));
    }

    public function resume(): void
    {
        if ($this->title->follow === null) {
            return;
        }

        $this->title->setRelation('follow', app(ResumeShow::class)->handle($this->title->follow));
    }

    public function confirmRestartShow(): void
    {
        Flux::modal('confirm-restart-show')->show();
    }

    /**
     * Restarts the show from S1E1: any follow state (including not-yet-followed) becomes
     * Watching with a fresh rewatch start date. History stays — only Up Next's progress resets.
     */
    public function confirmedRestartShow(): void
    {
        $this->title->setRelation('follow', app(RestartShow::class)->handle($this->title));

        Flux::modal('confirm-restart-show')->close();
    }

    /**
     * Ends an in-progress rewatch without finishing it: state is recomputed as usual (Completed
     * if everything has ever been watched, otherwise Watching).
     */
    public function stopRewatch(): void
    {
        if ($this->title->follow === null) {
            return;
        }

        $this->title->setRelation('follow', app(StopRewatch::class)->handle($this->title));
    }

    /**
     * Force-refreshes this title from TMDB regardless of staleness. Queued (never runs inline),
     * so the "…" menu item just confirms it was dispatched with a toast rather than blocking or
     * polling for completion.
     */
    public function refreshFromTmdb(): void
    {
        RefreshTitleFromTmdb::dispatch($this->title->id);
    }

    /**
     * The signed-in user's rating for this title (shared by the star row and the review flyout,
     * both now part of this page directly rather than the standalone `RateTitle` component,
     * which only this page ever used).
     */
    #[Computed]
    public function rating(): ?Rating
    {
        $this->title->loadMissing('rating');

        return $this->title->rating;
    }

    /**
     * @param  int  $score  half-star units: 1 = ½★ … 10 = ★★★★★
     */
    #[Renderless]
    public function setScore(int $score): void
    {
        if ($score < 1 || $score > 10) {
            return;
        }

        if ($this->rating?->score === $score) {
            app(ClearRatingScore::class)->handle($this->title);
        } else {
            app(SetRatingScore::class)->handle($this->title, $score);
        }

        $this->title->unsetRelation('rating');
        unset($this->rating);
    }

    public function openReviewFlyout(): void
    {
        $rating = $this->rating;

        $this->reviewText = $rating?->review ?? '';
        $this->reviewSpoilers = $rating?->review_spoilers ?? false;
        $this->watchedOn = ($rating?->reviewed_at ? DisplayTimezone::local($rating->reviewed_at) : $this->latestPlayedAt())?->toDateString();

        $this->resetValidation();

        Flux::modal('review-flyout')->show();
    }

    public function saveReview(): void
    {
        $this->validate([
            'reviewText' => ['nullable', 'string', 'max:5000'],
            'watchedOn' => ['nullable', 'date'],
        ]);

        $review = trim($this->reviewText) === '' ? null : $this->reviewText;
        $reviewedAt = $review !== null && $this->watchedOn ? DisplayTimezone::parseLocalToUtc($this->watchedOn) : null;

        app(SetRatingReview::class)->handle($this->title, $review, $this->reviewSpoilers, $reviewedAt);

        $this->title->unsetRelation('rating');
        unset($this->rating);
        Flux::modal('review-flyout')->close();
    }

    public function deleteReview(): void
    {
        app(ClearRatingReview::class)->handle($this->title);

        $this->reset(['reviewText', 'reviewSpoilers', 'watchedOn']);
        $this->title->unsetRelation('rating');
        unset($this->rating);
        Flux::modal('review-flyout')->close();
    }

    private function latestPlayedAt(): ?Carbon
    {
        $watchedAt = $this->title->isMovie()
            ? $this->title->plays()->max('watched_at')
            : Play::query()
                ->where('playable_type', 'episode')
                ->whereIn('playable_id', $this->title->episodes()->pluck('id'))
                ->max('watched_at');

        return $watchedAt ? DisplayTimezone::local($watchedAt) : null;
    }

    public function markWatched(string $when = 'now'): void
    {
        $this->applyWatched('movie', null, WatchedAt::from($when));
    }

    public function removePlay(int $playId): void
    {
        $play = $this->title->plays()->findOrFail($playId);

        app(RemovePlay::class)->handle($play);
    }

    public function markShowWatched(string $when = 'now'): void
    {
        $this->applyWatched('show', null, WatchedAt::from($when));
    }

    /**
     * "Mark watched" on the "Up next" card. Only ever targets `$this->nextUpEpisode`, but takes
     * the id explicitly (matching the episode-card menu items, which pass one) rather than
     * trusting client state.
     */
    public function markUpNextEpisode(int $episodeId, string $when = 'now'): void
    {
        $this->applyWatched('episode', $episodeId, WatchedAt::from($when));
    }

    public function confirmMarkShowWatched(string $when = 'now'): void
    {
        $this->pendingBulkWhen = $when;

        Flux::modal('confirm-show-watched')->show();
    }

    public function confirmedMarkShowWatched(): void
    {
        $this->markShowWatched($this->pendingBulkWhen);

        Flux::modal('confirm-show-watched')->close();
    }

    public function openTrailer(): void
    {
        $this->trailerOpen = true;

        Flux::modal('watch-trailer')->show();
    }

    public function closeTrailer(): void
    {
        $this->trailerOpen = false;
    }

    public function openSeasonTrailer(): void
    {
        $this->seasonTrailerOpen = true;

        Flux::modal('watch-season-trailer')->show();
    }

    public function closeSeasonTrailer(): void
    {
        $this->seasonTrailerOpen = false;
    }

    public function confirmCustomDatetime(string $customDatetimeTarget, string $customDatetime): void
    {
        $customDatetime = $this->resolveCustomDatetimeUtc($customDatetime);

        [$target, $episodeId] = array_pad(explode(':', $customDatetimeTarget, 2), 2, null);

        $this->applyWatched($target, $episodeId !== null ? (int) $episodeId : null, WatchedAt::Custom, $customDatetime);

        Flux::modal('custom-watched-at')->close();
    }

    private function applyWatched(string $target, ?int $episodeId, WatchedAt $when, ?CarbonImmutable $customDatetime = null): void
    {
        match ($target) {
            'movie' => app(LogPlay::class)->handle($this->title, $when, $customDatetime),
            'show' => app(MarkShowWatched::class)->handle($this->title, $when, $customDatetime),
            'episode' => app(LogPlay::class)->handle($this->title->episodes()->findOrFail($episodeId), $when, $customDatetime),
        };
    }
}; ?>

<div class="flex flex-1 flex-col pb-16" x-data="{ customDatetimeTarget: '', customDatetime: '' }">
    <div class="relative w-full">
        <div class="relative aspect-[8/3] w-full overflow-hidden bg-surface md:aspect-[7/2]">
            @if ($title->backdropUrl('w1280'))
                <img src="{{ $title->backdropUrl('w1280') }}" alt="" class="size-full object-cover object-[50%_25%]" />
            @else
                <div class="size-full bg-gradient-to-br from-surface-raised to-surface"></div>
            @endif

            <div class="absolute inset-0 bg-gradient-to-t from-canvas via-canvas/60 to-transparent"></div>
            <div class="absolute inset-0 bg-gradient-to-r from-canvas/80 via-transparent to-transparent"></div>
        </div>

        <x-page-container class="relative -mt-16 flex flex-col gap-4 pb-6 md:-mt-20 md:flex-row md:items-end">
            @if ($title->posterUrl('w342'))
                <img
                    src="{{ $title->posterUrl('w342') }}"
                    alt=""
                    class="hidden aspect-2/3 w-40 shrink-0 rounded-lg object-cover shadow-xl ring-1 ring-line md:block lg:w-48"
                />
            @endif

            <div class="flex flex-1 flex-col gap-3">
                {{-- Title row: Watch on Plex · Trailer · … right-aligned (drops under the title on mobile). --}}
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div class="flex flex-col gap-3">
                        <x-titles.network :title="$title" />

                        <div class="text-3xl font-bold text-ink sm:text-4xl">{{ $title->name }}</div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($this->deferredPending('plex'))
                            <flux:button size="sm" variant="ghost" icon="arrow-path" icon:class="animate-spin" disabled wire:poll.3s="pollDeferred('plex')">
                                {{ __('Checking Plex…') }}
                            </flux:button>
                        @elseif ($this->plexPlayUrl)
                            <x-media.plex-play-button :url="$this->plexPlayUrl" size="sm" branded />
                        @else
                            <livewire:request-title :title="$title" :key="'request-title-'.$title->id" />
                        @endif

                        <livewire:watchlist-toggle :title="$title" :key="'watchlist-toggle-'.$title->id" />

                        @if ($title->hasTrailer() && $this->seasonTrailer)
                            <flux:dropdown position="bottom" align="start">
                                <flux:button size="sm" variant="filled" icon="play-circle" icon:trailing="chevron-down">
                                    {{ __('Watch trailer') }}
                                </flux:button>

                                <flux:menu>
                                    <flux:menu.item wire:click="openTrailer">{{ __('Show trailer') }}</flux:menu.item>
                                    <flux:menu.item wire:click="openSeasonTrailer">
                                        {{ __('Season :number trailer', ['number' => $this->seasonTrailer->season_number]) }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        @elseif ($this->seasonTrailer)
                            <flux:button size="sm" variant="filled" icon="play-circle" wire:click="openSeasonTrailer">
                                {{ __('Season :number trailer', ['number' => $this->seasonTrailer->season_number]) }}
                            </flux:button>
                        @elseif ($title->hasTrailer())
                            <flux:button size="sm" variant="filled" icon="play-circle" wire:click="openTrailer">
                                {{ __('Watch trailer') }}
                            </flux:button>
                        @endif

                        @if ($this->deferredPending('show-trailer') || $this->deferredPending('season-trailer'))
                            @if (! $title->hasTrailer() && ! $this->seasonTrailer)
                                <flux:button size="sm" variant="ghost" icon="arrow-path" icon:class="animate-spin" disabled wire:poll.3s="pollTrailers">
                                    {{ __('Checking for trailer…') }}
                                </flux:button>
                            @else
                                <span wire:poll.3s="pollTrailers" class="sr-only" aria-hidden="true"></span>
                            @endif
                        @endif

                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="{{ __('More actions') }}" />

                            <flux:menu>
                                @if ($title->isShow())
                                    <flux:menu.item icon="check" wire:click="confirmMarkShowWatched">{{ __('Mark show watched') }}</flux:menu.item>

                                    <flux:menu.submenu icon="clock" heading="{{ __('Watched on…') }}">
                                        <flux:menu.item wire:click="confirmMarkShowWatched('release_date')">{{ __('On release date') }}</flux:menu.item>
                                        <flux:menu.item wire:click="confirmMarkShowWatched('unknown')">{{ __('Unknown date') }}</flux:menu.item>
                                        <flux:menu.item x-on:click="{{ CustomWatchedAtTrigger::open('custom-watched-at', 'show') }}">{{ __('Pick date & time…') }}</flux:menu.item>
                                    </flux:menu.submenu>

                                    @if ($this->followState === null)
                                        <flux:menu.item icon="plus" wire:click="follow">{{ __('Follow') }}</flux:menu.item>
                                    @else
                                        @if ($this->followState === FollowState::Watching)
                                            <flux:menu.item icon="pause" wire:click="pause">{{ __('Pause') }}</flux:menu.item>
                                        @elseif ($this->followState === FollowState::Paused || $this->followState === FollowState::Abandoned)
                                            <flux:menu.item icon="play" wire:click="resume">{{ __('Resume') }}</flux:menu.item>
                                        @endif

                                        @if ($this->isRewatching)
                                            <flux:menu.item icon="stop" wire:click="stopRewatch">{{ __('Stop rewatch') }}</flux:menu.item>
                                        @endif

                                        <flux:menu.item icon="arrow-path-rounded-square" wire:click="confirmRestartShow">{{ __('Restart show') }}</flux:menu.item>
                                    @endif
                                @else
                                    @if ($this->plays->isNotEmpty())
                                        <flux:menu.submenu icon="arrow-path-rounded-square" heading="{{ __('Watch again…') }}">
                                            <flux:menu.item wire:click="markWatched">{{ __('Now') }}</flux:menu.item>
                                            <flux:menu.item wire:click="markWatched('release_date')">{{ __('On release date') }}</flux:menu.item>
                                            <flux:menu.item wire:click="markWatched('unknown')">{{ __('Unknown date') }}</flux:menu.item>
                                            <flux:menu.item x-on:click="{{ CustomWatchedAtTrigger::open('custom-watched-at', 'movie') }}">{{ __('Pick date & time…') }}</flux:menu.item>
                                        </flux:menu.submenu>
                                    @else
                                        <flux:menu.item icon="check" wire:click="markWatched">{{ __('Mark watched') }}</flux:menu.item>

                                        <flux:menu.submenu icon="clock" heading="{{ __('Watched on…') }}">
                                            <flux:menu.item wire:click="markWatched('release_date')">{{ __('On release date') }}</flux:menu.item>
                                            <flux:menu.item wire:click="markWatched('unknown')">{{ __('Unknown date') }}</flux:menu.item>
                                            <flux:menu.item x-on:click="{{ CustomWatchedAtTrigger::open('custom-watched-at', 'movie') }}">{{ __('Pick date & time…') }}</flux:menu.item>
                                        </flux:menu.submenu>
                                    @endif
                                @endif

                                <flux:menu.item icon="pencil-square" wire:click="openReviewFlyout">
                                    {{ $this->rating?->review ? __('Edit review') : __('Add review') }}
                                </flux:menu.item>

                                <livewire:title-lists :title="$title" :key="'title-lists-'.$title->id" />

                                <flux:menu.item
                                    icon="arrow-path"
                                    x-on:click="
                                        $wire.refreshFromTmdb()
                                            .then(() => window.dispatchEvent(new CustomEvent('toast-show', { detail: { text: @js(__('Refresh queued.')) } })))
                                            .catch(() => window.dispatchEvent(new CustomEvent('toast-show', { detail: { variant: 'danger', text: @js(__('That didn’t save — please try again.')) } })))
                                    "
                                >
                                    {{ __('Refresh from TMDB') }}
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>

                {{-- Meta chips. The Seerr request control lives in the title row above, in the
                     "Watch on Plex" slot, whenever the title isn't on Plex. --}}
                <div class="flex flex-wrap items-center gap-2 text-sm text-ink-muted">
                    @if ($title->isShow() && $title->showStatus())
                        <x-media.chip :tone="$title->showStatus()->chipTone()">{{ $title->showStatus()->label() }}</x-media.chip>
                    @endif

                    @if ($title->yearRange())
                        <x-media.chip>{{ $title->yearRange() }}</x-media.chip>
                    @endif

                    @if ($title->isShow())
                        <x-media.chip>{{ __(':count seasons', ['count' => $this->orderedSeasons->count()]) }}</x-media.chip>
                    @elseif ($title->runtime)
                        <x-media.chip>{{ intdiv($title->runtime, 60) }}h {{ $title->runtime % 60 }}m</x-media.chip>
                    @endif

                    @foreach ($title->genres ?? [] as $genre)
                        <x-media.chip>{{ $genre }}</x-media.chip>
                    @endforeach
                </div>

                <x-titles.watch-providers :title="$title" />

                {{-- External ratings (compact) + the user's own interactive star rating. --}}
                <div class="flex flex-wrap items-center gap-3">
                    @if ($this->externalRatings->isNotEmpty())
                        <x-media.rating-badges :ratings="$this->externalRatings" />
                    @elseif ($this->deferredPending('ratings'))
                        <span class="h-6 w-24 animate-pulse rounded-md bg-zinc-200 dark:bg-zinc-700" aria-hidden="true"></span>
                    @endif

                    @if ($this->deferredPending('ratings'))
                        <span wire:poll.3s="pollDeferred('ratings')" class="sr-only" aria-hidden="true"></span>
                    @endif

                    <x-media.star-rating :value="$this->rating?->score" action="setScore" />
                </div>

                {{-- Watched status: one solid continuous line, follow state folded into the label. --}}
                @if ($title->isShow())
                    <x-media.watched-status
                        class="w-full max-w-md"
                        is-show
                        :follow-state="$this->followState"
                        :watched-count="$this->progress->watchedCount"
                        :aired-count="$this->progress->airedCount"
                    />
                @else
                    @php $lastPlayedAt = $this->plays->max('watched_at'); @endphp

                    <x-media.watched-status
                        class="w-full max-w-md"
                        :is-show="false"
                        :last-watched-at="$lastPlayedAt ? DisplayTimezone::local($lastPlayedAt) : null"
                    />
                @endif
            </div>
        </x-page-container>
    </div>

    <x-page-container class="flex flex-col gap-10 pt-8">
        @if ($title->overview)
            <p class="max-w-3xl text-sm leading-relaxed text-ink-muted">{{ $title->overview }}</p>
        @endif

        @if ($this->rating?->review)
            <div class="max-w-2xl" x-data="{ expanded: false, revealed: {{ $this->rating->review_spoilers ? 'false' : 'true' }} }">
                <p
                    class="text-sm text-ink-muted"
                    :class="{ 'line-clamp-3': !expanded, 'blur-sm select-none': !revealed }"
                >{{ $this->rating->review }}</p>

                <div class="mt-1 flex items-center gap-3">
                    <button
                        type="button"
                        x-show="!revealed"
                        x-cloak
                        class="text-xs font-medium text-accent-content hover:underline"
                        @click="revealed = true"
                    >
                        {{ __('Show spoiler') }}
                    </button>

                    <button
                        type="button"
                        class="text-xs font-medium text-ink-subtle hover:text-ink"
                        @click="expanded = !expanded"
                        x-text="expanded ? '{{ __('Show less') }}' : '{{ __('Read more') }}'"
                    ></button>

                    @if ($this->rating->reviewed_at)
                        <span class="text-xs text-ink-subtle">{{ __('Watched :date', ['date' => DisplayTimezone::local($this->rating->reviewed_at)->format('M j, Y')]) }}</span>
                    @endif
                </div>
            </div>
        @endif

        <livewire:collection-copies :title="$title" :key="'collection-copies-'.$title->id" />

        @if ($title->isShow())
            @if ($this->nextUpEpisode)
                @php
                    $nextUpEpisode = $this->nextUpEpisode;
                    $nextUpAired = $nextUpEpisode->hasAired();
                    $nextUpCode = sprintf('S%02dE%02d', $nextUpEpisode->season_number, $nextUpEpisode->episode_number);
                    $nextUpDate = $nextUpAired ? null : __('Airs :date', ['date' => $nextUpEpisode->air_date?->format('M j, Y') ?? __('TBA')]);
                    $nextUpHref = request()->fullUrlWithQuery(['episode' => $nextUpEpisode->id]);
                    $nextUpOnMarkWatched = 'markUpNextEpisode('.$nextUpEpisode->id.')';
                    $nextUpOnWatchedReleaseDate = sprintf("markUpNextEpisode(%d, 'release_date')", $nextUpEpisode->id);
                    $nextUpOnWatchedUnknown = sprintf("markUpNextEpisode(%d, 'unknown')", $nextUpEpisode->id);
                    $nextUpDatetimeTarget = 'episode:'.$nextUpEpisode->id;
                    $nextUpOnPickDatetime = CustomWatchedAtTrigger::open('custom-watched-at', $nextUpDatetimeTarget);
                    $nextUpSeasonHref = route('titles.seasons.show', [$title, $nextUpEpisode->season_number]);
                    $nextUpPlexAvailable = $nextUpAired && $this->plexPlayUrl !== null;
                @endphp

                <section class="flex flex-col gap-3">
                    <x-media.section-header :heading="__('Up next')" />

                    <div class="max-w-md">
                        <x-media.episode-card
                            wire:key="up-next-episode-{{ $nextUpEpisode->id }}"
                            :image="$nextUpEpisode->stillUrl('w300') ?? $title->backdropUrl('w300')"
                            :name="$title->name"
                            :code="$nextUpCode"
                            :episode-name="$nextUpEpisode->name"
                            :episode-id="$nextUpEpisode->id"
                            :date="$nextUpDate"
                            :href="$nextUpHref"
                            :can-mark-watched="$nextUpAired"
                            :on-mark-watched="$nextUpOnMarkWatched"
                            :on-watched-release-date="$nextUpOnWatchedReleaseDate"
                            :on-watched-unknown="$nextUpOnWatchedUnknown"
                            :on-pick-datetime="$nextUpOnPickDatetime"
                            :on-pick-datetime-target="$nextUpDatetimeTarget"
                            :plex-available="$nextUpPlexAvailable"
                            :plex-url="$this->plexPlayUrl"
                            :season-href="$nextUpSeasonHref"
                            :muted="! $nextUpAired"
                            optimistic-watched
                        />
                    </div>
                </section>
            @elseif ($title->in_production)
                <x-media.empty-state icon="check-circle" :heading="__('All caught up')">
                    {{ __('You\'re up to date with every aired episode of :title.', ['title' => $title->name]) }}
                </x-media.empty-state>
            @endif
        @endif

        @if ($title->isMovie())
            <section class="flex flex-col gap-3">
                <x-media.section-header :heading="__('Play history')" />

                @if ($this->plays->isEmpty())
                    <x-media.empty-state icon="clock" :heading="__('No plays yet')">
                        {{ __('Mark this movie watched above to start its history.') }}
                    </x-media.empty-state>
                @else
                    <div class="flex flex-col gap-2">
                        @foreach ($this->plays as $play)
                            <div
                                class="flex items-center justify-between gap-4 rounded-lg border border-line bg-surface px-4 py-3"
                                wire:key="play-{{ $play->id }}"
                                x-data="{ removed: false }"
                                x-show="!removed"
                                x-transition.opacity.duration.150ms
                            >
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-sm font-medium text-ink">{{ $play->watched_at ? DisplayTimezone::local($play->watched_at)->format('M j, Y g:ia') : __('Unknown date') }}</span>
                                    <span class="text-xs uppercase tracking-wide text-ink-subtle">{{ $play->source->value }}</span>
                                </div>

                                @if ($play->source === PlaySource::Manual)
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        x-on:click="removed = true; $wire.removePlay({{ $play->id }}).catch(() => { removed = false; window.dispatchEvent(new CustomEvent('toast-show', { detail: { variant: 'danger', text: @js(__('That didn’t save — please try again.')) } })); })"
                                    >
                                        {{ __('Remove') }}
                                    </flux:button>
                                @else
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="removePlay({{ $play->id }})"
                                        wire:confirm="{{ __('This play was recorded from :source, not logged manually here. Remove it anyway?', ['source' => $play->source->value]) }}"
                                    >
                                        {{ __('Remove') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        @else
            <x-media.poster-row :heading="__('Seasons')">
                @foreach ($this->orderedSeasons as $season)
                    @php $seasonProgress = $this->seasonProgress[$season->id] ?? null; @endphp

                    <x-media.poster-card
                        :image="$season->posterUrl() ?? $title->posterUrl()"
                        :name="$season->season_number === 0 ? __('Specials') : __('Season :number', ['number' => $season->season_number])"
                        :subtitle="$season->air_date ? $season->air_date->format('Y') : null"
                        :href="route('titles.seasons.show', [$title, $season->season_number])"
                        :progress="$seasonProgress['percent'] ?? null"
                        :watched="$seasonProgress['complete'] ?? false"
                        type="show"
                        wire:key="season-{{ $season->id }}"
                    >
                        <x-slot:meta>
                            <span>{{ __(':count episodes', ['count' => $season->episode_count ?? $season->episodes()->count()]) }}</span>
                        </x-slot:meta>
                    </x-media.poster-card>
                @endforeach
            </x-media.poster-row>
        @endif

        @if ($this->cast->isNotEmpty())
            <section class="flex flex-col gap-3">
                <x-media.section-header :heading="__('Top cast')" />

                <div
                    class="relative"
                    x-data="{
                        canScrollLeft: false,
                        canScrollRight: false,
                        scrollTimer: null,
                        updateFades() {
                            const track = $refs.track;
                            this.canScrollLeft = track.scrollLeft > 0;
                            this.canScrollRight = track.scrollLeft + track.clientWidth < track.scrollWidth - 1;
                        },
                    }"
                    x-init="updateFades(); $nextTick(() => updateFades())"
                    x-on:resize.window="updateFades()"
                >
                    <div x-show="canScrollLeft" x-transition.opacity class="pointer-events-none absolute inset-y-0 left-0 z-10 w-12 bg-gradient-to-r from-canvas to-transparent"></div>
                    <div x-show="canScrollRight" x-transition.opacity class="pointer-events-none absolute inset-y-0 right-0 z-10 w-16 bg-gradient-to-l from-canvas to-transparent"></div>

                    <div
                        x-ref="track"
                        class="scrollbar-autohide flex gap-4 overflow-x-auto pb-2"
                        x-on:scroll.passive="updateFades(); $el.dataset.scrolling = ''; clearTimeout(scrollTimer); scrollTimer = setTimeout(() => delete $el.dataset.scrolling, 800)"
                    >
                        @foreach ($this->cast as $credit)
                            <div class="flex w-24 shrink-0 flex-col items-center gap-2 text-center" wire:key="credit-{{ $credit->id }}">
                                <flux:avatar :src="$credit->person?->profileUrl('w185')" :name="$credit->person?->name" size="lg" circle />
                                <div class="flex flex-col">
                                    <span class="truncate text-xs font-medium text-ink">{{ $credit->person?->name }}</span>
                                    @if ($credit->character)
                                        <span class="truncate text-xs text-ink-subtle">{{ $credit->character }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </x-page-container>

    <x-media.trailer-modal
        name="watch-trailer"
        :open="$trailerOpen"
        :embed-url="$title->trailerEmbedUrl()"
        :watch-url="$title->trailerWatchUrl()"
        :site="$title->trailer_site"
        on-close="closeTrailer"
    />

    @if ($this->seasonTrailer)
        <x-media.trailer-modal
            name="watch-season-trailer"
            :open="$seasonTrailerOpen"
            :embed-url="$this->seasonTrailer->trailerEmbedUrl()"
            :watch-url="$this->seasonTrailer->trailerWatchUrl()"
            :site="$this->seasonTrailer->trailer_site"
            on-close="closeSeasonTrailer"
        />
    @endif

    <flux:modal name="custom-watched-at" class="md:w-96">
        <form
            x-on:submit.prevent="
                $flux.modal('custom-watched-at').close();
                $wire.confirmCustomDatetime(customDatetimeTarget, customDatetime).catch(() => {
                    window.dispatchEvent(new CustomEvent('toast-show', { detail: { variant: 'danger', text: @js(__('That didn’t save — please try again.')) } }));
                });
            "
            class="space-y-6"
        >
            <div>
                <flux:heading size="lg">{{ __('Pick a date & time') }}</flux:heading>

                <template x-if="customDatetimeTarget === 'show'">
                    <flux:text class="mt-2">
                        {{ __('This will mark all :count aired episodes of :title as watched.', ['count' => $this->airedUnwatchedCount, 'title' => $title->name]) }}
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

    <flux:modal name="confirm-show-watched" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Mark show watched?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Mark all :count aired episodes of :title as watched?', ['count' => $this->airedUnwatchedCount, 'title' => $title->name]) }}
                </flux:text>
            </div>

            <div class="flex justify-end gap-2" x-data="{ pending: false }">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    x-on:click="pending = true; $wire.confirmedMarkShowWatched().catch(() => window.dispatchEvent(new CustomEvent('toast-show', { detail: { variant: 'danger', text: @js(__('That didn’t save — please try again.')) } }))).finally(() => pending = false)"
                    x-bind:disabled="pending"
                >
                    <span x-show="!pending">{{ __('Mark show watched') }}</span>
                    <span x-show="pending" x-cloak class="inline-flex items-center gap-2">
                        <flux:icon.loading variant="micro" />
                        {{ __('Mark show watched') }}
                    </span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    @if ($title->isShow() && $this->followState !== null)
        <flux:modal name="confirm-restart-show" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Restart show?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('Start :title over from S1E1? Your history stays.', ['title' => $title->name]) }}
                    </flux:text>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="primary" wire:click="confirmedRestartShow">
                        {{ __('Restart show') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    <flux:modal
        name="review-flyout"
        variant="flyout"
        class="flex w-full flex-col gap-6 overflow-y-auto bg-canvas! border-line! md:max-w-lg [&::backdrop]:bg-canvas/50! [&::backdrop]:backdrop-blur-[2px]!"
    >
        <flux:heading size="lg">{{ $this->rating?->review ? __('Edit review') : __('Add review') }}</flux:heading>

        <x-media.star-rating :value="$this->rating?->score" action="setScore" size="lg" />

        <flux:field>
            <flux:label>{{ __('Review') }}</flux:label>
            <flux:textarea wire:model.live.debounce.300ms="reviewText" rows="6" :placeholder="__('What did you think?')" />
            <flux:description>{{ __(':count / 5000', ['count' => strlen($reviewText)]) }}</flux:description>
            <flux:error name="reviewText" />
        </flux:field>

        <flux:switch wire:model="reviewSpoilers" :label="__('Contains spoilers')" />

        <flux:field>
            <flux:label>{{ __('Watched on') }}</flux:label>
            <flux:input type="date" wire:model="watchedOn" />
            <flux:error name="watchedOn" />
        </flux:field>

        <div class="flex items-center justify-between gap-2">
            @if ($this->rating?->review)
                <flux:button variant="ghost" wire:click="deleteReview">{{ __('Delete review') }}</flux:button>
            @else
                <span></span>
            @endif

            <div class="flex gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" wire:click="saveReview">{{ __('Save') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
