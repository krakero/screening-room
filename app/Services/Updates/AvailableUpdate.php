<?php

namespace App\Services\Updates;

use App\Enums\UpdateChannel;

readonly class AvailableUpdate
{
    public function __construct(
        public string $version,
        public string $summary,
        public string $url,
        public string $published_at,
        public UpdateChannel $channel,
    ) {}
}
