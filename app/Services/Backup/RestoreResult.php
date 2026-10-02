<?php

namespace App\Services\Backup;

use App\Enums\BackupType;

readonly class RestoreResult
{
    /**
     * @param  array<string, int>  $tables  Table name => rows restored.
     * @param  array<int, string>  $skippedTables  Tables in the backup that were not restored (missing here, or never restored).
     */
    public function __construct(
        public BackupType $type,
        public array $tables,
        public int $configKeys,
        public ?string $safetyBackup,
        public array $skippedTables = [],
    ) {}
}
