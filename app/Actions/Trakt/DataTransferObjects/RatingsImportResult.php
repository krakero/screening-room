<?php

namespace App\Actions\Trakt\DataTransferObjects;

class RatingsImportResult
{
    public function __construct(
        public readonly int $imported,
        public readonly int $skipped,
    ) {}
}
