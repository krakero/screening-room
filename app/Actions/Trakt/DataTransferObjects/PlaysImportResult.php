<?php

namespace App\Actions\Trakt\DataTransferObjects;

class PlaysImportResult
{
    public function __construct(
        public readonly int $imported,
        public readonly int $skipped,
    ) {}
}
