<?php

namespace App\Services\Sonarr;

use RuntimeException;

class SonarrException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Sonarr is not configured.');
    }

    public static function requestFailed(string $endpoint, int $status, string $body): self
    {
        return new self("Sonarr request to [{$endpoint}] failed with status {$status}: {$body}");
    }

    public static function connectionFailed(string $endpoint, string $message): self
    {
        return new self("Sonarr request to [{$endpoint}] failed: {$message}");
    }
}
