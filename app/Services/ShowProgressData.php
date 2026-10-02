<?php

namespace App\Services;

use App\Models\Episode;

readonly class ShowProgressData
{
    public function __construct(
        public int $airedCount,
        public int $watchedCount,
        public float $percent,
        public ?Episode $nextEpisode,
        public bool $isComplete,
    ) {}
}
