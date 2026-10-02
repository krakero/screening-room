<?php

namespace App\Services\Radarr;

use RuntimeException;

class RadarrException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Radarr is not configured.');
    }

    public static function requestFailed(string $endpoint, int $status, string $body): self
    {
        return new self("Radarr request to [{$endpoint}] failed with status {$status}: {$body}");
    }

    public static function connectionFailed(string $endpoint, string $message): self
    {
        return new self("Radarr request to [{$endpoint}] failed: {$message}");
    }
}
