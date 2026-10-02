<?php

namespace App\Jobs;

use App\Actions\Trakt\ImportWatchlist;
use App\Services\Trakt\ExportReader;
use App\Support\TraktImportProgress;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Imports the watchlist for a batched Trakt export, on the persistent
 * working directory `ImportTraktExport` extracted the export to.
 */
class ImportTraktWatchlist implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 55;

    public int $tries = 3;

    public function __construct(
        public readonly string $workingDir,
        public readonly bool $dryRun = false,
    ) {}

    public function handle(ImportWatchlist $importWatchlist, TraktImportProgress $progress): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $importWatchlist->handle(new ExportReader($this->workingDir), $this->dryRun);

        if ($this->batch() !== null) {
            $progress->phase($this->batch()->id, 'watchlist', 1, 1);
        }
    }
}
