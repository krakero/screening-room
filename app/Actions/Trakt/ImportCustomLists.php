<?php

namespace App\Actions\Trakt;

use App\Actions\Trakt\Concerns\ResolvesTraktEntities;
use App\Actions\Trakt\DataTransferObjects\CustomListsImportResult;
use App\Models\MediaList;
use App\Models\MediaListItem;
use App\Services\Trakt\ExportReader;

class ImportCustomLists
{
    use ResolvesTraktEntities;

    public function handle(ExportReader $reader, bool $dryRun): CustomListsImportResult
    {
        $lists = 0;
        $itemsImported = 0;
        $itemsSkipped = 0;

        foreach ($reader->customLists() as $list) {
            $lists++;

            $mediaList = $dryRun ? null : MediaList::query()->updateOrCreate(
                ['slug' => $list['slug']],
                ['name' => $list['name'], 'description' => $list['description']],
            );

            $position = 0;

            foreach (collect($list['items'])->sortBy('rank')->values() as $entry) {
                $title = $this->resolveTitle($entry);

                if ($title === null) {
                    $itemsSkipped++;

                    continue;
                }

                $itemsImported++;

                if ($dryRun) {
                    continue;
                }

                MediaListItem::query()->updateOrCreate(
                    ['media_list_id' => $mediaList->id, 'title_id' => $title->id],
                    ['position' => $position],
                );

                $position++;
            }
        }

        return new CustomListsImportResult($lists, $itemsImported, $itemsSkipped);
    }
}
