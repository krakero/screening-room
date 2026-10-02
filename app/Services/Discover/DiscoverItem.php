<?php

namespace App\Services\Discover;

use App\Enums\TitleType;
use App\Models\Title;

final class DiscoverItem
{
    public function __construct(
        public readonly int $tmdbId,
        public readonly TitleType $type,
        public readonly string $name,
        public readonly ?string $year,
        public readonly ?string $poster,
        public readonly ?Title $title,
        public readonly bool $watched,
        public readonly ?string $status,
        public readonly bool $onList,
        public readonly bool $followed,
        public readonly bool $isReRelease = false,
    ) {}
}
