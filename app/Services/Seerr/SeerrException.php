<?php

namespace App\Services\Seerr;

use RuntimeException;

class SeerrException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Seerr is not configured.');
    }

    public static function requestFailed(string $endpoint, int $status, string $body): self
    {
        return new self("Seerr request to [{$endpoint}] failed with status {$status}: {$body}");
    }

    public static function connectionFailed(string $endpoint, string $message): self
    {
        return new self("Seerr request to [{$endpoint}] failed: {$message}");
    }
}
