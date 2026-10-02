<?php

namespace App\Actions\Trakt;

use App\Actions\Tmdb\ImportTitle;
use App\Actions\Trakt\DataTransferObjects\SkippedReference;
use App\Actions\Trakt\DataTransferObjects\TitleReference;
use App\Actions\Trakt\DataTransferObjects\TitlesImportResult;
use App\Models\Title;
use App\Services\Tmdb\TmdbException;

/**
 * Imports the titles referenced by a Trakt export that aren't already in
 * the database. Titles already present are left untouched, which makes
 * repeated (resumable) import runs cheap.
 */
class ImportMissingTitles
{
    public function __construct(
        private readonly ImportTitle $importTitle,
    ) {}

    /**
     * @param  array<int, TitleReference>  $references
     */
    public function handle(array $references, bool $dryRun, ?callable $onProgress = null): TitlesImportResult
    {
        $imported = 0;
        $alreadyPresent = 0;
        $skipped = [];

        foreach ($references as $reference) {
            $exists = Title::query()
                ->where('type', $reference->type)
                ->where('tmdb_id', $reference->tmdbId)
                ->exists();

            if ($exists) {
                $alreadyPresent++;
                $this->reportProgress($onProgress, $reference);

                continue;
            }

            if ($dryRun) {
                $imported++;
                $this->reportProgress($onProgress, $reference);

                continue;
            }

            try {
                $this->importTitle->handle($reference->type, $reference->tmdbId);
                $imported++;
            } catch (TmdbException $exception) {
                $skipped[] = new SkippedReference(
                    "{$reference->type->value} tmdb:{$reference->tmdbId}",
                    $this->describe($exception),
                );
            }

            $this->reportProgress($onProgress, $reference);
        }

        return new TitlesImportResult($imported, $alreadyPresent, $skipped);
    }

    private function reportProgress(?callable $onProgress, TitleReference $reference): void
    {
        if ($onProgress !== null) {
            $onProgress($reference);
        }
    }

    private function describe(TmdbException $exception): string
    {
        if (preg_match('/failed with status (\d+)/', $exception->getMessage(), $matches) === 1) {
            return $matches[1] === '404' ? 'not found on TMDB (404)' : "TMDB request failed ({$matches[1]})";
        }

        return 'TMDB request failed';
    }
}
