<?php

namespace App\Console\Commands;

use App\Actions\Plex\SyncPlexLibraryIndex;
use App\Support\IntegrationSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class IndexPlexLibrary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plex:index {--force : Remove stale rows and force-refresh titles even if this run looks incomplete}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuilds the local Plex library index (plex_library_items) and refreshes cached title availability from it.';

    public function handle(IntegrationSettings $settings, SyncPlexLibraryIndex $sync): int
    {
        if (! $settings->configured('plex.url', 'plex.token')) {
            $this->components->warn('Plex is not configured; skipping.');

            return self::SUCCESS;
        }

        $startedAt = Carbon::now();

        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current% [%bar%] %message%');
        $bar->setMessage('Starting…');
        $bar->start();

        try {
            $result = $sync->handle(
                force: (bool) $this->option('force'),
                onProgress: function (array $event) use ($bar): void {
                    $bar->setMessage("{$event['section']}: {$event['title']}");
                    $bar->advance();
                },
            );
        } catch (Throwable $exception) {
            $bar->finish();
            $this->newLine();

            $settings->set('plex.library_index', [
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'finished_at' => now()->toIso8601String(),
            ]);

            $this->components->error("Plex library sync failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine();

        $settings->set('plex.library_index', [
            'status' => 'success',
            'items_indexed' => $result['items_indexed'],
            'finished_at' => now()->toIso8601String(),
        ]);

        if ($result['cleanup_skipped']) {
            $this->components->warn("Skipped removing stale library rows: {$result['cleanup_skip_reason']}. Re-run with --force to override.");
        }

        $this->components->twoColumnDetail('Sections', (string) $result['sections']);
        $this->components->twoColumnDetail('Items indexed', (string) $result['items_indexed']);
        $this->components->twoColumnDetail('Episodes indexed', (string) $result['episodes_indexed']);
        $this->components->twoColumnDetail('Removed', (string) $result['removed']);
        $this->components->twoColumnDetail('Titles refreshed', (string) $result['titles_refreshed']);
        $this->components->twoColumnDetail('Elapsed', $startedAt->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE, short: true));

        return self::SUCCESS;
    }
}
