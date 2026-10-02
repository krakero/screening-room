<?php

namespace App\Jobs;

use App\Actions\Plex\SyncPlexLibraryIndex;
use App\Support\IntegrationSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued by the "Sync library now" button on Settings → Integrations → Plex.
 */
class SyncPlexLibrary implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function uniqueId(): string
    {
        return 'plex-library-sync';
    }

    public function handle(SyncPlexLibraryIndex $sync, IntegrationSettings $settings): void
    {
        try {
            $result = $sync->handle();
        } catch (Throwable $exception) {
            $settings->set('plex.library_index', [
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'finished_at' => now()->toIso8601String(),
            ]);

            throw $exception;
        }

        $settings->set('plex.library_index', [
            'status' => 'success',
            'items_indexed' => $result['items_indexed'],
            'finished_at' => now()->toIso8601String(),
        ]);
    }
}
