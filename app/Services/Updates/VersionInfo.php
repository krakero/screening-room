<?php

namespace App\Services\Updates;

use App\Enums\UpdateChannel;

readonly class VersionInfo
{
    public function __construct(
        public string $version,
        public UpdateChannel $channel,
        public ?string $commit = null,
    ) {}

    public function isDevelop(): bool
    {
        return $this->channel === UpdateChannel::Develop;
    }
}
