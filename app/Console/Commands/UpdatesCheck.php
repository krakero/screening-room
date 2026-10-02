<?php

namespace App\Console\Commands;

use App\Services\Updates\UpdateChecker;
use Illuminate\Console\Command;

class UpdatesCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'updates:check {--force : Force a fresh check, bypassing cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for available updates';

    public function handle(UpdateChecker $checker): int
    {
        $force = $this->option('force');

        if ($force) {
            $this->info('Checking for updates (bypassing cache)...');
        } else {
            $this->info('Checking for updates...');
        }

        $update = $checker->check($force);

        if ($update === null) {
            $this->components->info('No updates available');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->info("Update available: {$update->version}");
        $this->line("Summary: {$update->summary}");
        $this->line("Channel: {$update->channel->label()}");
        $this->line("Published: {$update->published_at}");
        $this->line("URL: {$update->url}");

        return self::SUCCESS;
    }
}
