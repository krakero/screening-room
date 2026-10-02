<?php

namespace App\Actions\Trakt\DataTransferObjects;

class TraktImportSummary
{
    public function __construct(
        public readonly bool $dryRun,
        public readonly TitlesImportResult $titles,
        public readonly PlaysImportResult $plays,
        public readonly RatingsImportResult $ratings,
        public readonly WatchlistImportResult $watchlist,
        public readonly CustomListsImportResult $lists,
    ) {}

    /**
     * @return array<string, array{label: string, imported: int, skipped: int}>
     */
    public function rows(): array
    {
        return [
            'titles' => ['label' => 'Titles', 'imported' => $this->titles->imported, 'skipped' => count($this->titles->skipped)],
            'plays' => ['label' => 'Plays', 'imported' => $this->plays->imported, 'skipped' => $this->plays->skipped],
            'ratings' => ['label' => 'Ratings', 'imported' => $this->ratings->imported, 'skipped' => $this->ratings->skipped],
            'watchlist' => ['label' => 'Watchlist items', 'imported' => $this->watchlist->imported, 'skipped' => $this->watchlist->skipped],
            'lists' => ['label' => 'Custom lists', 'imported' => $this->lists->lists, 'skipped' => 0],
            'list_items' => ['label' => 'Custom list items', 'imported' => $this->lists->itemsImported, 'skipped' => $this->lists->itemsSkipped],
        ];
    }
}
