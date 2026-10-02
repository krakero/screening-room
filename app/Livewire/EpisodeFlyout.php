<?php

namespace App\Livewire;

use App\Actions\Plays\LogPlay;
use App\Actions\Plays\RemovePlay;
use App\Actions\Plex\ResolvePlexAvailability;
use App\Enums\PlaySource;
use App\Enums\WatchedAt;
use App\Livewire\Concerns\HasCustomWatchedAt;
use App\Models\Episode;
use App\Models\Play;
use App\Support\WatchedSince;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mounted once in the app layout. Reads/writes the `?episode=` query string so any page can open
 * an episode's details without navigating away.
 *
 * The card that opens this flyout (`x-media.episode-card`) shows the modal client-side the
 * instant it's clicked (`$flux.modal('episode-flyout').show()`, alongside the `open-episode`
 * dispatch below) so there's no server round trip before something is visible; the view renders
 * a skeleton (`wire:loading` targeting `open`/`goTo`) until this component's response lands.
 */
class EpisodeFlyout extends Component
{
    use HasCustomWatchedAt;

    #[Url(as: 'episode', history: true)]
    public ?int $episodeId = null;

    /**
     * The Plex "play" link for the current episode, or null when unavailable/unresolved. A plain
     * property (not #[Computed]) so refreshPlex() can set it once per episode change instead of
     * every render re-querying it.
     */
    public ?string $plexUrl = null;

    /**
     * Set when a Plex resolve job is in flight for the current episode (no `plex_items` row yet);
     * cleared once it resolves or after ~30s. Drives the "Checking Plex…" pending state and the
     * `wire:poll.3s="checkPlex"` that watches for it, same pattern as the trailer polling on the
     * Title/Season pages.
     */
    public ?int $awaitingPlexDispatchedAt = null;

    public function mount(?int $episodeId = null): void
    {
        if ($episodeId !== null) {
            $this->episodeId = $episodeId;
        }

        if ($this->episodeId !== null) {
            Flux::modal('episode-flyout')->show();
            $this->refreshPlex();
        }
    }

    #[On('open-episode')]
    public function open(int $episodeId): void
    {
        $this->goTo($episodeId);

        Flux::modal('episode-flyout')->show();
    }

    public function close(): void
    {
        $this->episodeId = null;
    }

    public function goTo(int $episodeId): void
    {
        $this->episodeId = $episodeId;

        unset($this->episode, $this->plays, $this->previousEpisode, $this->nextEpisode, $this->seasonStats, $this->progressSince);

        $this->refreshPlex();
    }

    public function updatedEpisodeId(): void
    {
        if ($this->episodeId !== null) {
            Flux::modal('episode-flyout')->show();
        } else {
            Flux::modal('episode-flyout')->close();
        }
    }

    /**
     * Reads the episode's cached Plex availability only — never a live Plex call during render
     * (`ResolvePlexAvailability::forEpisode()`). When nothing is cached yet, that call queues a
     * background resolve job and this sets the awaiting flag so the view polls for it.
     */
    private function refreshPlex(): void
    {
        $episode = $this->episode;

        if (! $episode || ! $episode->hasAired()) {
            $this->plexUrl = null;
            $this->awaitingPlexDispatchedAt = null;

            return;
        }

        $resolver = app(ResolvePlexAvailability::class);

        $this->plexUrl = $resolver->forEpisode($episode)?->playUrl();
        $this->awaitingPlexDispatchedAt = $resolver->isPendingForEpisode($episode) ? now()->timestamp : null;
    }

    /**
     * Polled via `wire:poll.3s` only while a Plex lookup is in flight. A cheap existence check
     * (no Plex call) each tick; only once it resolves (or after ~30s) does it re-fetch the URL
     * and force a real render — `#[Renderless]` skips every tick in between.
     */
    #[Renderless]
    public function checkPlex(): void
    {
        if ($this->awaitingPlexDispatchedAt === null) {
            return;
        }

        $episode = $this->episode;
        $resolver = app(ResolvePlexAvailability::class);

        $resolved = ! $episode || ! $resolver->isPendingForEpisode($episode);
        $timedOut = now()->timestamp - $this->awaitingPlexDispatchedAt >= 30;

        if (! $resolved && ! $timedOut) {
            return;
        }

        $this->plexUrl = $episode ? $resolver->forEpisode($episode)?->playUrl() : null;
        $this->awaitingPlexDispatchedAt = null;
        $this->forceRender();
    }

    #[Computed]
    public function episode(): ?Episode
    {
        if ($this->episodeId === null) {
            return null;
        }

        return Episode::query()->with(['title.follow', 'season', 'plays'])->find($this->episodeId);
    }

    /**
     * While the show is rewatching, the watched checks below count only plays since this date.
     */
    #[Computed]
    public function progressSince(): ?CarbonInterface
    {
        return $this->episode?->title->follow?->progressSince();
    }

    /**
     * @return Collection<int, Play>
     */
    #[Computed]
    public function plays(): Collection
    {
        return $this->episode?->plays->sortByDesc('watched_at') ?? collect();
    }

    /**
     * @return array{total: int, watched: int}
     */
    #[Computed]
    public function seasonStats(): array
    {
        $season = $this->episode?->season;

        if (! $season) {
            return ['total' => 0, 'watched' => 0];
        }

        $since = $this->progressSince;

        return [
            'total' => $season->episode_count ?? $season->episodes()->count(),
            'watched' => $since === null
                ? $season->episodes()->whereHas('plays')->count()
                : $season->episodes()
                    ->whereHas('plays', fn ($query) => $query
                        ->where('watched_at', '>=', $since)
                        ->orWhere(fn ($query) => $query->whereNull('watched_at')->where('created_at', '>=', $since)))
                    ->count(),
        ];
    }

    /**
     * @return Collection<int, Episode>
     */
    #[Computed]
    protected function siblingEpisodes(): Collection
    {
        if (! $this->episode) {
            return collect();
        }

        return Episode::query()
            ->where('title_id', $this->episode->title_id)
            ->orderBy('season_number')
            ->orderBy('episode_number')
            ->get(['id', 'season_number', 'episode_number']);
    }

    #[Computed]
    public function previousEpisode(): ?Episode
    {
        if (! $this->episode) {
            return null;
        }

        $index = $this->siblingEpisodes->search(fn (Episode $episode) => $episode->id === $this->episode->id);

        return $index !== false && $index > 0 ? $this->siblingEpisodes->get($index - 1) : null;
    }

    #[Computed]
    public function nextEpisode(): ?Episode
    {
        if (! $this->episode) {
            return null;
        }

        $index = $this->siblingEpisodes->search(fn (Episode $episode) => $episode->id === $this->episode->id);

        return $index !== false ? $this->siblingEpisodes->get($index + 1) : null;
    }

    public function toggleWatched(string $when = 'now'): void
    {
        $episode = $this->episode;

        if (! $episode) {
            return;
        }

        $since = $this->progressSince;
        $countedPlays = $episode->plays->filter(fn (Play $play): bool => WatchedSince::watched(collect([$play]), $since));

        if ($countedPlays->isNotEmpty()) {
            $play = $countedPlays->firstWhere('source', PlaySource::Manual) ?? $countedPlays->first();

            app(RemovePlay::class)->handle($play);
        } else {
            app(LogPlay::class)->handle($episode, WatchedAt::from($when));
        }

        unset($this->episode, $this->plays, $this->seasonStats);

        $this->dispatch('episode-watched-changed', episodeId: $episode->id);
    }

    /**
     * "Watch again" on an already-watched episode: always adds a new play (never toggles off).
     */
    public function watchAgain(): void
    {
        $episode = $this->episode;

        if (! $episode) {
            return;
        }

        app(LogPlay::class)->handle($episode, WatchedAt::Now);

        unset($this->episode, $this->plays, $this->seasonStats);

        $this->dispatch('episode-watched-changed', episodeId: $episode->id);
    }

    public function confirmCustomDatetime(string $customDatetime): void
    {
        $customDatetime = $this->resolveCustomDatetimeUtc($customDatetime);

        $episode = $this->episode;

        if ($episode) {
            app(LogPlay::class)->handle($episode, WatchedAt::Custom, $customDatetime);

            unset($this->episode, $this->plays, $this->seasonStats);

            $this->dispatch('episode-watched-changed', episodeId: $episode->id);
        }

        Flux::modal('episode-flyout-custom-watched-at')->close();
    }

    public function render()
    {
        return view('livewire.episode-flyout');
    }
}
