<?php

namespace App\Services\Collection;

use App\Enums\CollectionFormat;
use App\Models\CollectionItem;
use App\Support\DisplayTimezone;
use Illuminate\Support\Facades\DB;

class CollectionStats
{
    /**
     * Build collection stats for the given year, or all time when null.
     *
     * @return array<string, mixed>
     */
    public function summary(?int $year = null): array
    {
        if (! auth()->check() || ! auth()->user()->collection_enabled) {
            return $this->emptyStats();
        }

        $itemsQuery = CollectionItem::query()
            ->when($year, fn ($q) => $q->whereBetween('acquired_at', DisplayTimezone::yearRangeUtc($year)));

        $copies = (int) $itemsQuery->clone()->count();

        if ($copies === 0) {
            return $this->emptyStats();
        }

        $titles = (int) $itemsQuery->clone()->distinct()->count('title_id');

        $movies = (int) $itemsQuery->clone()
            ->join('titles as t', 't.id', '=', 'collection_items.title_id')
            ->where('t.type', 'movie')
            ->distinct()
            ->count('t.id');

        $shows = (int) $itemsQuery->clone()
            ->join('titles as t', 't.id', '=', 'collection_items.title_id')
            ->where('t.type', 'show')
            ->distinct()
            ->count('t.id');

        $byFormat = $this->formatBreakdown($year);
        $totalSpent = $this->spendingByCurrency($year);
        $loanedOut = $this->loanedOutCount($year);
        $inPlexOnly = $this->inPlexOnlyCount();
        $addedThisYear = $this->addedThisYear();

        return [
            'copies' => $copies,
            'titles' => $titles,
            'movies' => $movies,
            'shows' => $shows,
            'by_format' => $byFormat,
            'total_spent' => $totalSpent,
            'loaned_out' => $loanedOut,
            'in_plex_only' => $inPlexOnly,
            'added_this_year' => $addedThisYear,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStats(): array
    {
        return [
            'copies' => 0,
            'titles' => 0,
            'movies' => 0,
            'shows' => 0,
            'by_format' => [],
            'total_spent' => [],
            'loaned_out' => 0,
            'in_plex_only' => 0,
            'added_this_year' => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function formatBreakdown(?int $year): array
    {
        $counts = CollectionItem::query()
            ->when($year, fn ($q) => $q->whereBetween('acquired_at', DisplayTimezone::yearRangeUtc($year)))
            ->selectRaw('format, COUNT(*) as count')
            ->groupBy('format')
            ->pluck('count', 'format');

        $result = [];

        foreach (CollectionFormat::cases() as $format) {
            $count = (int) ($counts[$format->value] ?? 0);

            if ($count > 0) {
                $result[$format->value] = $count;
            }
        }

        return $result;
    }

    /**
     * @return array<string, float>
     */
    private function spendingByCurrency(?int $year): array
    {
        $spending = CollectionItem::query()
            ->when($year, fn ($q) => $q->whereBetween('acquired_at', DisplayTimezone::yearRangeUtc($year)))
            ->whereNotNull('price')
            ->whereNotNull('currency')
            ->selectRaw('currency, SUM(price) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        return $spending->map(fn ($total) => (float) $total)->all();
    }

    private function loanedOutCount(?int $year): int
    {
        return (int) CollectionItem::query()
            ->when($year, fn ($q) => $q->whereBetween('acquired_at', DisplayTimezone::yearRangeUtc($year)))
            ->whereNotNull('loaned_at')
            ->whereNotNull('loaned_to')
            ->count();
    }

    /**
     * Count titles in Plex with no physical/digital copy in the collection.
     */
    private function inPlexOnlyCount(): int
    {
        $titlesInCollection = CollectionItem::query()
            ->distinct()
            ->pluck('title_id');

        return (int) DB::table('plex_items')
            ->where('plexable_type', 'title')
            ->whereNotNull('rating_key')
            ->whereNotIn('plexable_id', $titlesInCollection)
            ->distinct()
            ->count('plexable_id');
    }

    private function addedThisYear(): int
    {
        $currentYear = DisplayTimezone::today()->year;

        return (int) CollectionItem::query()
            ->whereBetween('acquired_at', DisplayTimezone::yearRangeUtc($currentYear))
            ->count();
    }
}
