<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupRestorer;
use Illuminate\Console\Command;

class RestoreBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:restore
        {file : Path to a backup zip, or the file name of a backup in the backup folder}
        {--password= : The backup password (defaults to the saved one)}
        {--force : Restore without asking for confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore a backup, replacing the current data and settings';

    public function handle(BackupRestorer $restorer, BackupManager $backups): int
    {
        if (! $this->option('force') && ! $this->confirm('This replaces ALL current data and settings with the backup (a safety backup is made first). Continue?')) {
            $this->info('Restore cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = $restorer->restore($this->resolvePath($backups), $this->option('password'));
        } catch (BackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored a {$result->type->value} backup. Safety backup of the previous state: {$result->safetyBackup}");

        foreach ($result->tables as $table => $rows) {
            $this->line("  {$table}: {$rows} rows");
        }

        if ($result->skippedTables !== []) {
            $this->warn('Skipped tables: '.implode(', ', $result->skippedTables));
        }

        $this->line("  settings: {$result->configKeys} keys");

        return self::SUCCESS;
    }

    private function resolvePath(BackupManager $backups): string
    {
        $file = (string) $this->argument('file');

        return is_file($file) ? $file : $backups->path($file);
    }
}
