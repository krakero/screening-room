<?php

namespace App\Services\UpNext;

use App\Enums\FollowState;
use App\Models\Episode;
use App\Models\Follow;
use App\Support\DisplayTimezone;
use Illuminate\Support\Collection;

class AiringThisWeekQuery
{
    /**
     * @return Collection<int, Episode>
     */
    public function get(): Collection
    {
        $followedTitleIds = Follow::query()
            ->whereNotIn('state', [FollowState::Abandoned, FollowState::Paused])
            ->pluck('title_id');

        return Episode::query()
            ->whereIn('title_id', $followedTitleIds)
            ->where('season_number', '!=', 0)
            ->whereBetween('air_date', [DisplayTimezone::today(), DisplayTimezone::today()->addDays(7)])
            ->whereDoesntHave('plays')
            ->with(['title', 'plexItem'])
            ->orderBy('air_date')
            ->get();
    }

    /**
     * @return Collection<string, Collection<int, Episode>>
     */
    public function byDay(): Collection
    {
        return $this->get()->groupBy(fn (Episode $episode): string => $episode->air_date->toDateString());
    }
}
