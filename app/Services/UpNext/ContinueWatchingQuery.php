<?php

namespace App\Services\UpNext;

use App\Enums\FollowState;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Title;
use App\Services\ShowProgress;
use App\Services\ShowProgressData;
use App\Support\DisplayTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContinueWatchingQuery
{
    /**
     * A show whose next episode is in an older season stays on Continue Watching only if
     * the episode before it was watched within this many days. A show with nothing watched
     * yet is exempt: following it is the signal to start, so its first episode is shown.
     */
    private const RECENT_WATCH_DAYS = 30;

    public function __construct(private readonly ShowProgress $showProgress) {}

    /**
     * @return Collection<int, array{title: Title, progress: ShowProgressData}>
     */
    public function get(): Collection
    {
        $follows = Follow::query()
            ->where('state', FollowState::Watching)
            ->with('title')
            ->orderByDesc('last_played_at')
            ->get();

        $titles = $follows->pluck('title');
        $progressByTitleId = $this->showProgress->forMany($titles);

        $entries = $follows
            ->map(fn (Follow $follow): array => [
                'title' => $follow->title,
                'progress' => $progressByTitleId->get($follow->title_id),
            ])
            ->filter(fn (array $entry): bool => $entry['progress']?->nextEpisode !== null)
            ->values();

        if ($entries->isEmpty()) {
            return $entries;
        }

        $currentSeasonByTitleId = $this->currentSeasonNumbers($entries->pluck('title.id'));

        $olderSeasonEntries = $entries->reject(
            fn (array $entry): bool => $this->isFreshStart($entry)
                || $entry['progress']->nextEpisode->season_number === $currentSeasonByTitleId->get($entry['title']->id)
        );

        $previousEpisodeIdByTitleId = $this->previousEpisodeIds($olderSeasonEntries);
        $recentlyWatchedEpisodeIds = $this->recentlyWatchedEpisodeIds($previousEpisodeIdByTitleId->filter()->values());

        return $entries
            ->filter(function (array $entry) use ($currentSeasonByTitleId, $previousEpisodeIdByTitleId, $recentlyWatchedEpisodeIds): bool {
                $titleId = $entry['title']->id;

                if ($this->isFreshStart($entry) || $entry['progress']->nextEpisode->season_number === $currentSeasonByTitleId->get($titleId)) {
                    return true;
                }

                $previousEpisodeId = $previousEpisodeIdByTitleId->get($titleId);

                return $previousEpisodeId !== null && $recentlyWatchedEpisodeIds->contains($previousEpisodeId);
            })
            ->values();
    }

    /**
     * @param  array{title: Title, progress: ShowProgressData}  $entry
     */
    private function isFreshStart(array $entry): bool
    {
        return $entry['progress']->watchedCount === 0;
    }

    /**
     * The latest season with at least one aired episode, per title. One aggregate query,
     * no episode rows are hydrated.
     *
     * @param  Collection<int, int>  $titleIds
     * @return Collection<int, int> keyed by title_id
     */
    private function currentSeasonNumbers(Collection $titleIds): Collection
    {
        if ($titleIds->isEmpty()) {
            return collect();
        }

        return DB::table('episodes')
            ->select('title_id')
            ->selectRaw('max(season_number) as current_season')
            ->whereIn('title_id', $titleIds)
            ->where('season_number', '!=', 0)
            ->whereNotNull('air_date')
            ->where('air_date', '<=', DisplayTimezone::today())
            ->groupBy('title_id')
            ->get()
            ->pluck('current_season', 'title_id');
    }

    /**
     * The episode immediately before each entry's next episode, for shows whose next episode
     * isn't in the current season. A single query (a window function over just those shows'
     * episodes), independent of how many other shows or episodes exist.
     *
     * @param  Collection<int, array{title: Title, progress: ShowProgressData}>  $entries
     * @return Collection<int, int> previous episode id keyed by title_id
     */
    private function previousEpisodeIds(Collection $entries): Collection
    {
        if ($entries->isEmpty()) {
            return collect();
        }

        $ordered = Episode::query()
            ->select('id', 'title_id')
            ->selectRaw('lag(id) over (partition by title_id order by season_number, episode_number) as previous_episode_id')
            ->whereIn('title_id', $entries->pluck('title.id'))
            ->where('season_number', '!=', 0)
            ->whereNotNull('air_date')
            ->where('air_date', '<=', DisplayTimezone::today());

        return DB::query()
            ->fromSub($ordered, 'ordered_episodes')
            ->whereIn('id', $entries->map(fn (array $entry): int => $entry['progress']->nextEpisode->id))
            ->get(['title_id', 'previous_episode_id'])
            ->pluck('previous_episode_id', 'title_id');
    }

    /**
     * @param  Collection<int, int>  $episodeIds
     * @return Collection<int, int>
     */
    private function recentlyWatchedEpisodeIds(Collection $episodeIds): Collection
    {
        if ($episodeIds->isEmpty()) {
            return collect();
        }

        return Play::query()
            ->where('playable_type', 'episode')
            ->whereIn('playable_id', $episodeIds)
            ->where('watched_at', '>=', Carbon::now()->subDays(self::RECENT_WATCH_DAYS))
            ->pluck('playable_id')
            ->unique();
    }
}
