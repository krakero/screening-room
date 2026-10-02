<?php

namespace App\Support;

use App\Models\Title;
use Illuminate\Support\Arr;

/**
 * Reads/writes the `trakt.last_import` status the Trakt settings page polls
 * while a batched import is running. Each pipeline job reports its own
 * phase/counts directly; with the default single queue worker these updates
 * are naturally serialized, so a plain read-merge-write is safe enough for
 * this single-user app.
 */
class TraktImportProgress
{
    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * @param  string  $exportPath  Path (on the `local` disk) to the uploaded export, kept so failed titles can be retried later without re-uploading.
     */
    public function starting(string $batchId, int $titlesTotal, int $playsTotal, string $exportPath, bool $dryRun): void
    {
        $this->settings->set('trakt.last_import', [
            'status' => 'running',
            'batch_id' => $batchId,
            // Kept separate from `batch_id` (which `retarget()` repoints to
            // the tail batch once every title job has settled) because a
            // title job's own `failed()` call can still be pending when that
            // repoint happens — `Batch::recordFailedJob()` (and so `finally()`
            // and the retarget it triggers) runs *before* the failed queue
            // job's `failed()` method, not after. `titleFailed()` needs a
            // batch id that keeps meaning "the titles batch" regardless.
            'titles_batch_id' => $batchId,
            'started_at' => now()->toIso8601String(),
            'phase' => 'titles',
            'current' => 0,
            'total' => $titlesTotal,
            'titles_total' => $titlesTotal,
            'plays_total' => $playsTotal,
            'export_path' => $exportPath,
            'dry_run' => $dryRun,
        ]);
    }

    /**
     * Repoints the running import's tracked batch id, so `phase()`/`finished()`
     * calls from a follow-up batch's jobs (dispatched after the first batch's
     * jobs have all settled) are still recognised as the same logical import.
     */
    public function retarget(string $fromBatchId, string $toBatchId): void
    {
        $state = $this->settings->get('trakt.last_import');

        if (! is_array($state) || ($state['batch_id'] ?? null) !== $fromBatchId) {
            return;
        }

        $this->settings->set('trakt.last_import', [
            ...$state,
            'batch_id' => $toBatchId,
        ]);
    }

    public function phase(string $batchId, string $phase, int $current, int $total): void
    {
        $state = $this->settings->get('trakt.last_import');

        if (! is_array($state) || ($state['batch_id'] ?? null) !== $batchId) {
            return;
        }

        $this->settings->set('trakt.last_import', [
            ...$state,
            'phase' => $phase,
            'current' => $current,
            'total' => $total,
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function finished(string $batchId, string $status, array $summary = [], ?string $error = null): void
    {
        $state = $this->settings->get('trakt.last_import');

        if (! is_array($state) || ($state['batch_id'] ?? null) !== $batchId) {
            return;
        }

        $this->settings->set('trakt.last_import', array_filter([
            'status' => $status,
            'batch_id' => $batchId,
            'finished_at' => now()->toIso8601String(),
            'summary' => $summary === [] ? null : $summary,
            'error' => $error,
            // Carried over so a "Retry failed" click after the page has
            // reloaded (long after this batch settled) still has an export
            // to re-read and titles to retry.
            'export_path' => $state['export_path'] ?? null,
            'dry_run' => $state['dry_run'] ?? null,
            'failed_titles' => ($state['failed_titles'] ?? []) === [] ? null : $state['failed_titles'],
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Records a title job's titles as permanently failed (see
     * `ImportTraktTitles::failed()`), keyed by `TitleReference::key()` so a
     * later call for the same title (this job retrying, or a "Retry failed"
     * re-run) replaces its entry rather than duplicating it. Matched against
     * `titles_batch_id` rather than `batch_id` — see the comment in
     * `starting()` for why.
     *
     * @param  array<int, array{type: string, tmdb_id: int}>  $references
     */
    public function titleFailed(string $batchId, array $references, string $error): void
    {
        $state = $this->settings->get('trakt.last_import');

        if (! is_array($state) || ($state['titles_batch_id'] ?? null) !== $batchId) {
            return;
        }

        $failedTitles = $state['failed_titles'] ?? [];

        foreach ($references as $reference) {
            $failedTitles["{$reference['type']}:{$reference['tmdb_id']}"] = [
                'type' => $reference['type'],
                'tmdb_id' => $reference['tmdb_id'],
                'error' => $error,
            ];
        }

        $this->settings->set('trakt.last_import', [
            ...$state,
            'failed_titles' => $failedTitles,
        ]);
    }

    /**
     * Marks a "retry failed titles" batch as running, re-pointing the
     * tracked batch id (like `retarget()`) so this batch's `ImportTraktTitles`
     * jobs' own `phase()`/`failed()` calls are recognised, while keeping the
     * prior run's summary/failed-titles list intact until `retryFinished()`.
     *
     * @param  array<int, string>  $referenceKeys  `TitleReference::key()` for every title being retried.
     */
    public function startingRetry(string $retryBatchId, array $referenceKeys): void
    {
        $state = $this->settings->get('trakt.last_import');

        if (! is_array($state)) {
            return;
        }

        $this->settings->set('trakt.last_import', [
            ...$state,
            'batch_id' => $retryBatchId,
            'titles_batch_id' => $retryBatchId,
            'status' => 'running',
            'phase' => 'titles',
            'current' => 0,
            'total' => count($referenceKeys),
            'retrying_titles' => $referenceKeys,
        ]);
    }

    /**
     * Finishes a "retry failed titles" batch: a retried title is considered
     * resolved once it exists in the database (regardless of whether its
     * plays also succeeded — those aren't independently retryable), so it's
     * dropped from `failed_titles`; a title that failed again already has
     * its entry refreshed by `titleFailed()` and is left in place.
     */
    public function retryFinished(string $retryBatchId): void
    {
        $state = $this->settings->get('trakt.last_import');

        if (! is_array($state) || ($state['batch_id'] ?? null) !== $retryBatchId) {
            return;
        }

        $failedTitles = $state['failed_titles'] ?? [];

        foreach ($state['retrying_titles'] ?? [] as $key) {
            if (! isset($failedTitles[$key])) {
                continue;
            }

            [$type, $tmdbId] = explode(':', $key, 2);

            if (Title::query()->where('type', $type)->where('tmdb_id', (int) $tmdbId)->exists()) {
                unset($failedTitles[$key]);
            }
        }

        $this->settings->set('trakt.last_import', [
            ...Arr::except($state, ['retrying_titles']),
            'status' => $failedTitles === [] ? 'success' : 'completed_with_errors',
            'failed_titles' => $failedTitles === [] ? null : $failedTitles,
            'finished_at' => now()->toIso8601String(),
        ]);
    }
}
