<?php

namespace App\Jobs;

use App\Actions\Trakt\ImportRatings;
use App\Services\Trakt\ExportReader;
use App\Support\TraktImportProgress;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Imports ratings (movies, shows, episodes) for a batched Trakt export.
 * Runs after title and play imports, on the persistent working directory
 * `ImportTraktExport` extracted the export to.
 */
class ImportTraktRatings implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 55;

    public int $tries = 3;

    public function __construct(
        public readonly string $workingDir,
        public readonly bool $dryRun = false,
    ) {}

    public function handle(ImportRatings $importRatings, TraktImportProgress $progress): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $importRatings->handle(new ExportReader($this->workingDir), $this->dryRun);

        if ($this->batch() !== null) {
            $progress->phase($this->batch()->id, 'ratings', 1, 1);
        }
    }
}
