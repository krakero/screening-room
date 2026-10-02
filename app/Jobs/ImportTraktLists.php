<?php

namespace App\Jobs;

use App\Actions\Trakt\ImportCustomLists;
use App\Services\Trakt\ExportReader;
use App\Support\TraktImportProgress;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Imports custom lists for a batched Trakt export, on the persistent
 * working directory `ImportTraktExport` extracted the export to. The last
 * job in the chained tail of the batch.
 */
class ImportTraktLists implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 55;

    public int $tries = 3;

    public function __construct(
        public readonly string $workingDir,
        public readonly bool $dryRun = false,
    ) {}

    public function handle(ImportCustomLists $importCustomLists, TraktImportProgress $progress): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $importCustomLists->handle(new ExportReader($this->workingDir), $this->dryRun);

        if ($this->batch() !== null) {
            $progress->phase($this->batch()->id, 'lists', 1, 1);
        }
    }
}
