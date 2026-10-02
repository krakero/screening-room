<?php

namespace App\Actions\Trakt\DataTransferObjects;

use App\Enums\TitleType;

class TitleReference
{
    public function __construct(
        public readonly TitleType $type,
        public readonly int $tmdbId,
    ) {}

    public function key(): string
    {
        return "{$this->type->value}:{$this->tmdbId}";
    }
}
