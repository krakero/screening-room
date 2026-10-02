<?php

namespace App\Services\Backup;

use App\Enums\BackupType;
use Carbon\CarbonImmutable;

final readonly class BackupFile
{
    public function __construct(
        public string $filename,
        public BackupType $type,
        public int $size,
        public CarbonImmutable $createdAt,
    ) {}
}
