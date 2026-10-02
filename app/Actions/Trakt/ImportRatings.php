<?php

namespace App\Actions\Trakt;

use App\Actions\Trakt\Concerns\ResolvesTraktEntities;
use App\Actions\Trakt\DataTransferObjects\RatingsImportResult;
use App\Models\Title;
use App\Services\Trakt\ExportReader;

class ImportRatings
{
    use ResolvesTraktEntities;

    public function handle(ExportReader $reader, bool $dryRun): RatingsImportResult
    {
        $imported = 0;
        $skipped = 0;

        foreach ($reader->ratedTitles() as $entry) {
            $this->apply($this->resolveTitle($entry), $entry['rating'], $dryRun, $imported, $skipped);
        }

        // Episode (and season) ratings are no longer supported; count them as skipped.
        $skipped += count(iterator_to_array($reader->ratedEpisodes()));

        return new RatingsImportResult($imported, $skipped);
    }

    private function apply(?Title $rateable, int $rating, bool $dryRun, int &$imported, int &$skipped): void
    {
        if ($rateable === null) {
            $skipped++;

            return;
        }

        $imported++;

        if ($dryRun) {
            return;
        }

        $rateable->rating()->updateOrCreate([], [
            'score' => $rating,
        ]);
    }
}
