<?php

namespace App\Console\Commands;

use App\Enums\BackupType;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManager;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:run {--config-only : Back up only the integration settings, not the data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates a password-protected backup zip of the app\'s data and settings';

    public function handle(BackupManager $backups): int
    {
        try {
            $file = $backups->create($this->option('config-only') ? BackupType::Config : BackupType::Full);
        } catch (BackupException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Backup created: {$file->filename}");

        return self::SUCCESS;
    }
}
