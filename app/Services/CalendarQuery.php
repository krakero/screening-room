<?php

namespace App\Services;

use App\Enums\CalendarEntryType;
use App\Enums\FollowState;
use App\Enums\TitleType;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\MediaList;
use App\Models\Play;
use App\Models\Title;
use App\Support\DisplayTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CalendarQuery
{
    /**
     * Episodes of followed shows, Watchlist movie releases, and season premieres of
     * unstarted Watchlist shows, for the given inclusive date range.
     *
     * @return Collection<int, CalendarEntry>
     */
    public function forRange(Carbon $start, Carbon $end): Collection
    {
        return $this->followedEpisodes($start->clone()->startOfDay(), $end->clone()->endOfDay())
            ->merge($this->watchlistMovieReleases($start, $end))
            ->merge($this->watchlistSeasonPremieres($start, $end))
            ->sortBy(fn (CalendarEntry $entry): int => $entry->date->timestamp)
            ->values();
    }

    /**
     * Episodes of followed shows airing today.
     *
     * @return Collection<int, CalendarEntry>
     */
    public function airingToday(): Collection
    {
        $today = DisplayTimezone::today();

        return $this->followedEpisodes($today, $today->clone()->endOfDay());
    }

    /**
     * Episodes of followed shows that aired in the last 7 days but haven't been watched yet.
     *
     * @return Collection<int, CalendarEntry>
     */
    public function catchUp(): Collection
    {
        $followedTitleIds = $this->followedTitleIds();

        if ($followedTitleIds->isEmpty()) {
            return collect();
        }

        $episodes = Episode::query()
            ->whereIn('title_id', $followedTitleIds)
            ->where('season_number', '!=', 0)
            ->whereBetween('air_date', [DisplayTimezone::today()->subDays(7), DisplayTimezone::today()->subDay()])
            ->with(['title', 'plexItem'])
            ->orderBy('air_date')
            ->get();

        $watchedEpisodeIds = $this->watchedEpisodeIds($episodes->pluck('id'));

        return $episodes
            ->reject(fn (Episode $episode): bool => $watchedEpisodeIds->contains($episode->id))
            ->map(fn (Episode $episode): CalendarEntry => new CalendarEntry(
                type: CalendarEntryType::Episode,
                date: Carbon::instance($episode->air_date),
                title: $episode->title,
                episode: $episode,
                watched: false,
            ))
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function followedTitleIds(): Collection
    {
        return Follow::query()
            ->whereNotIn('state', [FollowState::Abandoned, FollowState::Paused])
            ->pluck('title_id');
    }

    /**
     * @return Collection<int, CalendarEntry>
     */
    private function followedEpisodes(Carbon $start, Carbon $end): Collection
    {
        $followedTitleIds = $this->followedTitleIds();

        if ($followedTitleIds->isEmpty()) {
            return collect();
        }

        $episodes = Episode::query()
            ->whereIn('title_id', $followedTitleIds)
            ->where('season_number', '!=', 0)
            ->whereBetween('air_date', [$start, $end])
            ->with(['title', 'plexItem'])
            ->get();

        $watchedEpisodeIds = $this->watchedEpisodeIds($episodes->pluck('id'));

        return $episodes
            ->map(fn (Episode $episode): CalendarEntry => new CalendarEntry(
                type: CalendarEntryType::Episode,
                date: Carbon::instance($episode->air_date),
                title: $episode->title,
                episode: $episode,
                watched: $watchedEpisodeIds->contains($episode->id),
            ))
            ->values();
    }

    /**
     * @return Collection<int, CalendarEntry>
     */
    private function watchlistMovieReleases(Carbon $start, Carbon $end): Collection
    {
        return MediaList::watchlist()->titles()
            ->where('type', TitleType::Movie)
            ->whereBetween('release_date', [$start->toDateString(), $end->toDateString()])
            ->with('plexItem')
            ->get()
            ->map(fn (Title $title): CalendarEntry => new CalendarEntry(
                type: CalendarEntryType::MovieRelease,
                date: Carbon::instance($title->release_date)->startOfDay(),
                title: $title,
            ));
    }

    /**
     * Season premieres (episode 1 of a non-special season) for Watchlist shows that have no plays yet.
     *
     * @return Collection<int, CalendarEntry>
     */
    private function watchlistSeasonPremieres(Carbon $start, Carbon $end): Collection
    {
        $watchlistShowIds = MediaList::watchlist()->titles()
            ->where('type', TitleType::Show)
            ->pluck('titles.id');

        if ($watchlistShowIds->isEmpty()) {
            return collect();
        }

        $startedShowIds = Play::query()
            ->where('playable_type', 'episode')
            ->join('episodes', 'episodes.id', '=', 'plays.playable_id')
            ->whereIn('episodes.title_id', $watchlistShowIds)
            ->pluck('episodes.title_id')
            ->unique();

        $notStartedShowIds = $watchlistShowIds->diff($startedShowIds);

        if ($notStartedShowIds->isEmpty()) {
            return collect();
        }

        return Episode::query()
            ->whereIn('title_id', $notStartedShowIds)
            ->where('episode_number', 1)
            ->where('season_number', '!=', 0)
            ->whereBetween('air_date', [$start->toDateString(), $end->toDateString()])
            ->with(['title', 'plexItem'])
            ->get()
            ->map(fn (Episode $episode): CalendarEntry => new CalendarEntry(
                type: CalendarEntryType::SeasonPremiere,
                date: Carbon::instance($episode->air_date)->startOfDay(),
                title: $episode->title,
                episode: $episode,
            ));
    }

    /**
     * @param  Collection<int, int>  $episodeIds
     * @return Collection<int, int>
     */
    private function watchedEpisodeIds(Collection $episodeIds): Collection
    {
        if ($episodeIds->isEmpty()) {
            return collect();
        }

        return Play::query()
            ->where('playable_type', 'episode')
            ->whereIn('playable_id', $episodeIds)
            ->pluck('playable_id')
            ->unique();
    }
}
