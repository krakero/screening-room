<?php

namespace App\Services\UpNext;

use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Support\Collection;

class RecentlyWatchlistedQuery
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * Titles on the Watchlist, most recently added to it first.
     *
     * @return Collection<int, Title>
     */
    public function get(int $limit = 12): Collection
    {
        return Title::query()
            ->select('titles.*')
            ->join('media_list_items', 'media_list_items.title_id', '=', 'titles.id')
            ->join('media_lists', 'media_lists.id', '=', 'media_list_items.media_list_id')
            ->where('media_lists.is_watchlist', true)
            ->when($this->plexConfigured(), fn ($query) => $query->with('plexItem'))
            ->orderByDesc('media_list_items.created_at')
            ->orderByDesc('media_list_items.id')
            ->limit($limit)
            ->get();
    }

    /**
     * Read-only: reflects cached plex_items rows only, never a live Plex call.
     */
    public function plexConfigured(): bool
    {
        return $this->settings->configured('plex.url', 'plex.token');
    }
}
