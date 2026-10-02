<?php

namespace App\Jobs;

use App\Services\Trakt\ExportReader;
use App\Support\TraktImportProgress;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Re-runs just the titles a Trakt import's `ImportTraktTitles` jobs
 * permanently failed on, using the original export re-extracted to a fresh
 * (throwaway) working directory — the shared working directory the failed
 * import used is long gone by the time a user clicks "Retry failed", but
 * the uploaded export itself is never deleted from the `local` disk, so it
 * can still supply each failed title's watch-history entries.
 */
class RetryFailedTraktTitles implements ShouldQueue
{
    use Queueable;

    public int $timeout = 55;

    public int $tries = 3;

    /**
     * @param  string  $path  Path to the original uploaded export, relative to the `local` disk (`trakt.last_import.export_path`).
     * @param  array<int, array{type: string, tmdb_id: int}>  $references  The titles to retry.
     */
    public function __construct(
        public readonly string $path,
        public readonly array $references,
        public readonly bool $dryRun = false,
    ) {}

    public function handle(TraktImportProgress $progress): void
    {
        $workingDir = storage_path('app/private/imports/trakt-retry-'.Str::uuid()->toString());

        try {
            File::ensureDirectoryExists($workingDir);

            ExportReader::extractPersistent(Storage::disk('local')->path($this->path), $workingDir);

            $playGroups = $this->groupHistoryByTitle(new ExportReader($workingDir));

            $keys = [];
            $jobs = [];

            foreach ($this->references as $reference) {
                $key = "{$reference['type']}:{$reference['tmdb_id']}";
                $keys[] = $key;

                $entries = $playGroups[$key] ?? [];
                $playsFile = $entries === [] ? null : $this->writePlaysFile($workingDir, $key, $entries);

                $jobs[] = new ImportTraktTitles([$reference], 0, 1, $this->dryRun, $playsFile, 0, count($entries));
            }

            Bus::batch($jobs)
                ->name('Trakt import (retry failed titles)')
                ->allowFailures()
                ->before(fn (Batch $batch) => $progress->startingRetry($batch->id, $keys))
                ->finally(function (Batch $batch) use ($workingDir, $progress): void {
                    $progress->retryFinished($batch->id);

                    File::deleteDirectory($workingDir);
                })
                ->dispatch();
        } catch (Throwable $exception) {
            File::deleteDirectory($workingDir);

            throw $exception;
        }
    }

    /**
     * Groups watch-history entries by the title they belong to, keyed the
     * same way as `TitleReference::key()` — see `ImportTraktExport`'s
     * identical grouping for why an entry with no resolvable reference is
     * dropped here rather than counted as skipped: unlike the initial
     * import, a retry only ever runs `ImportTraktTitles` for known titles,
     * never the "unassigned plays" tail-of-last-chunk file.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupHistoryByTitle(ExportReader $reader): array
    {
        $groups = [];

        foreach ($reader->history() as $entry) {
            $key = match ($entry['type'] ?? null) {
                'movie' => isset($entry['movie']['ids']['tmdb']) ? "movie:{$entry['movie']['ids']['tmdb']}" : null,
                'episode' => isset($entry['show']['ids']['tmdb']) ? "show:{$entry['show']['ids']['tmdb']}" : null,
                default => null,
            };

            if ($key === null) {
                continue;
            }

            $groups[$key][] = $entry;
        }

        return $groups;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function writePlaysFile(string $workingDir, string $key, array $entries): string
    {
        $file = $workingDir.'/trakt-retry-plays-'.str_replace(':', '-', $key).'.json';

        File::put($file, json_encode($entries));

        return $file;
    }
}
