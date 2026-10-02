<?php

namespace App\Jobs;

use App\Actions\Trakt\DataTransferObjects\TitleReference;
use App\Actions\Trakt\ImportMissingTitles;
use App\Actions\Trakt\ImportPlays;
use App\Enums\TitleType;
use App\Services\Trakt\ExportReader;
use App\Support\TraktImportProgress;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * Imports one title referenced by a Trakt export — whether or not it's
 * already in the database — then immediately imports the watch-history
 * plays for that same title (`$playsFile`, that title's slice of history
 * entries `ImportTraktExport` grouped by title and wrote to the working
 * directory). This is what makes plays appear progressively as titles
 * complete instead of waiting for every job in the batch to settle. A
 * title that fails to import (e.g. a 404 from TMDB, recorded by
 * `ImportMissingTitles` without failing the job) simply can't be resolved
 * by `ImportPlays`, so its history entries are counted as skipped rather
 * than losing the rest of the batch's plays. The batch itself uses
 * `allowFailures()` so this job permanently failing (`failed()` below,
 * e.g. it timed out) doesn't cancel the rest.
 *
 * `$references` holds more than one title only when this job is replaying
 * a chunk built before one-title-per-job (retained for flexibility, e.g. a
 * future re-chunk), so `handle()` and `failed()` both still loop over it.
 *
 * Ratings/watchlist/custom-list imports aren't title-scoped the way plays
 * are, so they stay in the tail dispatched from `ImportTraktExport`'s
 * `finally()` callback once every job here has settled — see that class's
 * docblock for why that dispatch can't safely happen from inside this job.
 */
class ImportTraktTitles implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 55;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<int, array{type: string, tmdb_id: int}>  $references
     * @param  ?string  $playsFile  Path to this chunk's slice of watch-history entries (for the titles in `$references`, plus any history entries `ImportTraktExport` couldn't assign to a title), or null if there are none.
     */
    public function __construct(
        public readonly array $references,
        public readonly int $processedBefore,
        public readonly int $titlesTotal,
        public readonly bool $dryRun = false,
        public readonly ?string $playsFile = null,
        public readonly int $playsProcessedBefore = 0,
        public readonly int $playsTotal = 0,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('trakt-import-tmdb')];
    }

    /**
     * A time-based limit rather than `$tries`, because `RateLimited::release()`
     * still counts as an attempt: with a plain `$tries` count, a busy import
     * could exhaust its retries on rate-limit releases alone and fail before
     * ever getting a real attempt at the TMDB import.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(ImportMissingTitles $importMissingTitles, ImportPlays $importPlays, TraktImportProgress $progress): void
    {
        $batch = $this->batch();

        if ($batch?->cancelled()) {
            return;
        }

        $references = array_map(
            fn (array $reference): TitleReference => new TitleReference(TitleType::from($reference['type']), $reference['tmdb_id']),
            $this->references,
        );

        $importMissingTitles->handle($references, $this->dryRun);

        if ($batch !== null) {
            $progress->phase($batch->id, 'titles', $this->processedBefore + count($this->references), $this->titlesTotal);
        }

        if ($this->playsFile === null) {
            return;
        }

        $entries = ExportReader::decodeJsonFile($this->playsFile);

        $importPlays->handle($entries, $this->dryRun);

        if ($batch !== null) {
            $progress->phase($batch->id, 'plays', $this->playsProcessedBefore + count($entries), $this->playsTotal);
        }
    }

    /**
     * Called once this job has permanently failed (retries exhausted, or
     * `retryUntil()` passed) rather than a title being individually skipped
     * inside `ImportMissingTitles` — that case doesn't throw, so it never
     * reaches here. Records each of this job's titles as failed so the
     * import progress UI can list them with a reason and retry just those.
     */
    public function failed(?Throwable $exception): void
    {
        $batch = $this->batch();

        if ($batch === null) {
            return;
        }

        app(TraktImportProgress::class)->titleFailed(
            $batch->id,
            $this->references,
            $exception?->getMessage() ?? 'The job failed permanently.',
        );
    }
}
