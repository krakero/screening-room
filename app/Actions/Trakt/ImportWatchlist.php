<?php

namespace App\Actions\Trakt;

use App\Actions\Trakt\Concerns\ResolvesTraktEntities;
use App\Actions\Trakt\DataTransferObjects\WatchlistImportResult;
use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Services\Trakt\ExportReader;

class ImportWatchlist
{
    use ResolvesTraktEntities;

    public function handle(ExportReader $reader, bool $dryRun): WatchlistImportResult
    {
        $entries = collect($reader->watchlist())->sortBy('rank')->values();

        $imported = 0;
        $skipped = 0;
        $mediaList = $dryRun ? null : MediaList::watchlist();
        $position = 0;

        foreach ($entries as $entry) {
            $title = $this->resolveTitle($entry);

            if ($title === null) {
                $skipped++;

                continue;
            }

            $imported++;

            if ($dryRun) {
                continue;
            }

            MediaListItem::query()->updateOrCreate(
                ['media_list_id' => $mediaList->id, 'title_id' => $title->id],
                ['position' => $position],
            );

            $position++;
        }

        return new WatchlistImportResult($imported, $skipped);
    }
}
