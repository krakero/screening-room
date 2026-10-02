<?php

namespace App\Actions\Trakt\DataTransferObjects;

class CustomListsImportResult
{
    public function __construct(
        public readonly int $lists,
        public readonly int $itemsImported,
        public readonly int $itemsSkipped,
    ) {}
}
