<?php

namespace App\Console\Commands;

use App\Actions\Plex\ImportRecentHistory;
use App\Support\IntegrationSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PollPlex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plex:poll {--since=20 minutes ago : Anything Carbon::parse() understands} {--dry-run : Resolve matches without recording plays}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill Plex plays from recent watch history (requires Server URL + Token)';

    public function handle(ImportRecentHistory $importRecentHistory, IntegrationSettings $settings): int
    {
        if (! $settings->configured('plex.url', 'plex.token')) {
            $this->components->error('Plex Server URL and Token must be configured first (Settings → Integrations → Plex).');

            return self::FAILURE;
        }

        $since = Carbon::parse((string) $this->option('since'));
        $dryRun = (bool) $this->option('dry-run');

        $summary = $importRecentHistory->handle($since, $dryRun);

        $this->table(
            ['Fetched', 'Recorded', 'Duplicates', 'Ignored (account)', 'Unmatched'],
            [[
                $summary['fetched'],
                $summary['recorded'],
                $summary['duplicates'],
                $summary['ignored_account'],
                $summary['unmatched'],
            ]],
        );

        if ($summary['unmatched_items'] !== []) {
            $this->line('Unmatched:');

            foreach ($summary['unmatched_items'] as $item) {
                $this->line("  - {$item}");
            }
        }

        if ($dryRun) {
            $this->components->info('Dry run: no plays were recorded.');
        }

        return self::SUCCESS;
    }
}
