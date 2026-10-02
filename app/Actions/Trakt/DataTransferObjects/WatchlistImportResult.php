<?php

namespace App\Actions\Trakt\DataTransferObjects;

class WatchlistImportResult
{
    public function __construct(
        public readonly int $imported,
        public readonly int $skipped,
    ) {}
}
