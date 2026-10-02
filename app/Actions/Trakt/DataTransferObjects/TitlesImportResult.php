<?php

namespace App\Actions\Trakt\DataTransferObjects;

class TitlesImportResult
{
    /**
     * @param  array<int, SkippedReference>  $skipped
     */
    public function __construct(
        public readonly int $imported,
        public readonly int $alreadyPresent,
        public readonly array $skipped,
    ) {}
}
