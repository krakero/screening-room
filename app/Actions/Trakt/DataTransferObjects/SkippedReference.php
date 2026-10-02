<?php

namespace App\Actions\Trakt\DataTransferObjects;

class SkippedReference
{
    public function __construct(
        public readonly string $context,
        public readonly string $reason,
    ) {}
}
