<?php

namespace App\Jobs;

use App\Actions\Trakt\CollectTitleReferences;
use App\Actions\Trakt\DataTransferObjects\TitleReference;
use App\Actions\Trakt\ImportTraktExport as ImportTraktExportAction;
use App\Services\Trakt\ExportReader;
use App\Support\IntegrationSettings;
use App\Support\TraktImportProgress;
use Illuminate\Bus\Batch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entry point for a batched Trakt export import. Extracts the export once
 * to a persistent working directory, collects the referenced titles, groups
 * the watch-history entries by the title they belong to, then dispatches a
 * `Bus::batch()` of one `ImportTraktTitles` job per title — each of which
 * imports that title and then immediately imports the watch-history plays
 * for it, so plays appear progressively as titles complete instead of
 * waiting for the whole batch to finish. Once that batch's `finally()`
 * callback runs — i.e. once every title chunk has settled, whether it
 * succeeded or permanently failed — a second follow-up `Bus::batch()` is
 * dispatched with the remaining `ImportTraktRatings`/`ImportTraktWatchlist`/
 * `ImportTraktLists` jobs, which aren't title-scoped the way plays are and
 * only need referenced titles to already exist locally by the time they run.
 * Splitting the import into two batches like this (rather than extending the
 * first batch with the tail from inside a title job) means no single job
 * runs long enough to be killed by the local queue worker's default timeout
 * or double-run past the database queue's `retry_after`, and sidesteps a
 * `finally()`/`failed()` ordering race — see `ImportTraktTitles`'s docblock.
 */
class ImportTraktExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 55;

    public int $tries = 3;

    /**
     * One title per job (rather than a batch of several) keeps every job's
     * work small and predictable — a single big show's season/episode fetch
     * can't blow through the worker's process timeout alongside nine other
     * titles' worth of work, and a permanently failed job only ever loses
     * that one title (see `ImportTraktTitles::failed()`).
     */
    private const TITLES_PER_CHUNK = 1;

    /**
     * @param  string  $path  Path to the uploaded export, relative to the `local` disk (as returned by `UploadedFile::store()`).
     * @param  array<int, string>  $only
     */
    public function __construct(
        public readonly string $path,
        public readonly bool $dryRun = false,
        public readonly array $only = [],
    ) {}

    public function uniqueId(): string
    {
        return $this->path;
    }

    public function handle(
        CollectTitleReferences $collectTitleReferences,
        IntegrationSettings $integrationSettings,
        TraktImportProgress $progress,
    ): void {
        $only = $this->only === [] ? ImportTraktExportAction::SECTIONS : $this->only;
        $workingDir = storage_path('app/private/imports/trakt-'.Str::uuid()->toString());

        try {
            File::ensureDirectoryExists($workingDir);

            ExportReader::extractPersistent(Storage::disk('local')->path($this->path), $workingDir);

            $reader = new ExportReader($workingDir);
            $collected = $collectTitleReferences->handle($reader, $only);

            $titlesTotal = count($collected['references']);

            [$playGroups, $unassignedPlays, $playsTotal] = in_array('history', $only, true)
                ? $this->groupHistoryByTitle($reader)
                : [[], [], 0];

            $tail = [];

            if (in_array('ratings', $only, true)) {
                $tail[] = new ImportTraktRatings($workingDir, $this->dryRun);
            }

            if (in_array('watchlist', $only, true)) {
                $tail[] = new ImportTraktWatchlist($workingDir, $this->dryRun);
            }

            if (in_array('lists', $only, true)) {
                $tail[] = new ImportTraktLists($workingDir, $this->dryRun);
            }

            $titleJobs = $this->buildTitleJobs($collected['references'], $playGroups, $unassignedPlays, $workingDir, $titlesTotal, $playsTotal);

            $this->buildBatch($titleJobs, $workingDir, $progress, $titlesTotal, $playsTotal, $tail)->dispatch();
        } catch (Throwable $exception) {
            File::deleteDirectory($workingDir);

            $integrationSettings->set('trakt.last_import', [
                'status' => 'failed',
                'finished_at' => now()->toIso8601String(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Groups watch-history entries by the title they belong to (a movie's
     * TMDB id, or a show's TMDB id for an episode), keyed the same way as
     * `TitleReference::key()`, so each title chunk below can be handed the
     * slice of history it needs. Entries with no resolvable title reference
     * (an unrecognized `type`, or a missing TMDB id) are returned separately
     * rather than dropped, so they still flow through `ImportPlays` (and get
     * counted as skipped) exactly as they would if grouping didn't exist.
     *
     * @return array{0: array<string, array<int, array<string, mixed>>>, 1: array<int, array<string, mixed>>, 2: int}
     */
    private function groupHistoryByTitle(ExportReader $reader): array
    {
        $groups = [];
        $unassigned = [];
        $total = 0;

        foreach ($reader->history() as $entry) {
            $total++;

            $key = match ($entry['type'] ?? null) {
                'movie' => isset($entry['movie']['ids']['tmdb']) ? "movie:{$entry['movie']['ids']['tmdb']}" : null,
                'episode' => isset($entry['show']['ids']['tmdb']) ? "show:{$entry['show']['ids']['tmdb']}" : null,
                default => null,
            };

            if ($key === null) {
                $unassigned[] = $entry;

                continue;
            }

            $groups[$key][] = $entry;
        }

        return [$groups, $unassigned, $total];
    }

    /**
     * @param  array<int, TitleReference>  $references
     * @param  array<string, array<int, array<string, mixed>>>  $playGroups
     * @param  array<int, array<string, mixed>>  $unassignedPlays
     * @return array<int, ImportTraktTitles>
     */
    private function buildTitleJobs(array $references, array $playGroups, array $unassignedPlays, string $workingDir, int $titlesTotal, int $playsTotal): array
    {
        $chunks = collect($references)
            ->map(fn ($reference): array => ['type' => $reference->type->value, 'tmdb_id' => $reference->tmdbId])
            ->chunk(self::TITLES_PER_CHUNK)
            ->values();

        if ($chunks->isEmpty()) {
            $playsFile = $unassignedPlays === [] ? null : $this->writePlaysFile($workingDir, 0, $unassignedPlays);

            return [new ImportTraktTitles([], 0, 0, $this->dryRun, $playsFile, 0, $playsTotal)];
        }

        $lastChunkIndex = $chunks->count() - 1;
        $playsProcessed = 0;
        $jobs = [];

        foreach ($chunks as $index => $chunk) {
            $entries = collect($chunk->values()->all())
                ->flatMap(fn (array $reference): array => $playGroups["{$reference['type']}:{$reference['tmdb_id']}"] ?? [])
                ->values()
                ->all();

            if ($index === $lastChunkIndex) {
                $entries = array_merge($entries, $unassignedPlays);
            }

            $playsFile = $entries === [] ? null : $this->writePlaysFile($workingDir, $index, $entries);

            $jobs[] = new ImportTraktTitles(
                $chunk->values()->all(),
                $index * self::TITLES_PER_CHUNK,
                $titlesTotal,
                $this->dryRun,
                $playsFile,
                $playsProcessed,
                $playsTotal,
            );

            $playsProcessed += count($entries);
        }

        return $jobs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function writePlaysFile(string $workingDir, int $chunkIndex, array $entries): string
    {
        $file = $workingDir.'/trakt-plays-chunk-'.$chunkIndex.'.json';

        File::put($file, json_encode($entries));

        return $file;
    }

    /**
     * @param  array<int, ImportTraktTitles>  $titleJobs
     * @param  array<int, ShouldQueue>  $tail
     */
    private function buildBatch(
        array $titleJobs,
        string $workingDir,
        TraktImportProgress $progress,
        int $titlesTotal,
        int $playsTotal,
        array $tail,
    ): PendingBatch {
        // Captured into locals (rather than read as `$this->path`/`$this->dryRun`
        // from inside the closures below) so those closures don't need `$this`
        // bound at all — a batch's `before`/`finally` callbacks are persisted
        // as serialized closures, and binding the whole job instance into that
        // serialized payload is unnecessary weight this class doesn't need.
        $exportPath = $this->path;
        $dryRun = $this->dryRun;

        return Bus::batch($titleJobs)
            ->name('Trakt import')
            ->allowFailures()
            ->before(function (Batch $batch) use ($progress, $titlesTotal, $playsTotal, $exportPath, $dryRun): void {
                // Fires once the batch row exists (with its real id) but before
                // any of its jobs run, so this "running" status can't be
                // clobbered by a job that already finished by the time
                // `dispatch()` returns — which happens immediately under the
                // `sync` queue driver, since jobs run inline as they're added.
                $progress->starting($batch->id, $titlesTotal, $playsTotal, $exportPath, $dryRun);
            })
            ->finally(function (Batch $batch) use ($workingDir, $progress, $tail): void {
                $titlesFailed = $batch->hasFailures();

                if (! $batch->cancelled() && $tail !== []) {
                    self::dispatchTail($batch, $tail, $titlesFailed, $workingDir, $progress);

                    return;
                }

                $status = match (true) {
                    $batch->cancelled() => 'cancelled',
                    $titlesFailed => 'completed_with_errors',
                    default => 'success',
                };

                $progress->finished($batch->id, $status, [
                    'titles_total' => $batch->totalJobs,
                    'failed_jobs' => $batch->failedJobs,
                ]);

                File::deleteDirectory($workingDir);
            });
    }

    /**
     * Dispatched once every `ImportTraktTitles` chunk has settled (see the
     * `finally()` callback above), so referenced titles already exist and
     * the working directory hasn't been touched yet. Its own `finally()`
     * callback — not the titles batch's — is what finalises the import
     * status and cleans up the working directory, so the progress UI keeps
     * reporting "running" for the whole two-batch import.
     *
     * @param  array<int, ShouldQueue>  $tail
     */
    private static function dispatchTail(Batch $titlesBatch, array $tail, bool $titlesFailed, string $workingDir, TraktImportProgress $progress): void
    {
        // Only plain scalars are captured into the batch closures below, not
        // `$titlesBatch` itself — it (like any `Batch` instance) holds a
        // repository bound to a live database connection, which can't be
        // serialized once this dispatches onto a real (non-`sync`) queue.
        $titlesBatchId = $titlesBatch->id;
        $titlesTotalJobs = $titlesBatch->totalJobs;
        $titlesFailedJobs = $titlesBatch->failedJobs;

        try {
            Bus::batch($tail)
                ->name('Trakt import (history)')
                ->allowFailures()
                ->before(function (Batch $tailBatch) use ($progress, $titlesBatchId): void {
                    $progress->retarget($titlesBatchId, $tailBatch->id);
                })
                ->finally(function (Batch $tailBatch) use ($workingDir, $progress, $titlesTotalJobs, $titlesFailedJobs, $titlesFailed): void {
                    $status = match (true) {
                        $tailBatch->cancelled() => 'cancelled',
                        $titlesFailed || $tailBatch->hasFailures() => 'completed_with_errors',
                        default => 'success',
                    };

                    $progress->finished($tailBatch->id, $status, [
                        'titles_total' => $titlesTotalJobs,
                        'titles_failed' => $titlesFailedJobs,
                        'history_failed' => $tailBatch->failedJobs,
                    ]);

                    File::deleteDirectory($workingDir);
                })
                ->dispatch();
        } catch (Throwable $exception) {
            $progress->finished($titlesBatchId, 'failed_incomplete', [
                'titles_total' => $titlesTotalJobs,
                'failed_jobs' => $titlesFailedJobs,
            ], $exception->getMessage());

            File::deleteDirectory($workingDir);
        }
    }
}
