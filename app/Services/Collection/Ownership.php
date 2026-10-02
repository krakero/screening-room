<?php

namespace App\Services\Collection;

use App\Models\CollectionItem;
use App\Models\PlexLibraryEpisode;
use App\Models\Season;
use App\Models\Title;

class Ownership
{
    public function forTitle(Title $title): OwnershipSummary
    {
        if (! $this->isEnabled()) {
            return new OwnershipSummary(collect(), false);
        }

        $title->loadMissing('plexItem', 'collectionItems');
        $copies = $title->collectionItems;
        $inPlex = $title->plexItem?->found() ?? false;

        return new OwnershipSummary($copies, $inPlex);
    }

    public function forSeason(Season $season): OwnershipSummary
    {
        if (! $this->isEnabled()) {
            return new OwnershipSummary(collect(), false);
        }

        $copies = $season->collectionItems;

        $season->loadMissing('title.plexItem');
        $title = $season->title;
        $titleInPlex = $title->plexItem?->found() ?? false;

        $inPlex = false;
        if ($titleInPlex) {
            $inPlex = PlexLibraryEpisode::query()
                ->where('show_rating_key', $title->plexItem->rating_key)
                ->where('season_number', $season->season_number)
                ->exists();
        }

        return new OwnershipSummary($copies, $inPlex);
    }

    /**
     * @param  iterable<Title>  $titles
     * @return array<int, OwnershipSummary>
     */
    public function forTitles(iterable $titles): array
    {
        if (! $this->isEnabled()) {
            return collect($titles)
                ->mapWithKeys(fn (Title $title) => [$title->id => new OwnershipSummary(collect(), false)])
                ->all();
        }

        $titleIds = collect($titles)->pluck('id')->all();

        $copiesByTitleId = CollectionItem::query()
            ->whereIn('title_id', $titleIds)
            ->whereNull('season_id')
            ->get()
            ->groupBy('title_id');

        $plexItemsByTitleId = collect($titles)
            ->filter(fn (Title $title) => $title->plexItem?->found() ?? false)
            ->pluck('id')
            ->flip();

        return collect($titles)
            ->mapWithKeys(function (Title $title) use ($copiesByTitleId, $plexItemsByTitleId) {
                $copies = $copiesByTitleId->get($title->id, collect());
                $inPlex = $plexItemsByTitleId->has($title->id);

                return [$title->id => new OwnershipSummary($copies, $inPlex)];
            })
            ->all();
    }

    public function isEnabled(): bool
    {
        $user = auth()->user();

        return $user && $user->collection_enabled;
    }
}
