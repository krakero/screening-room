<?php

namespace App\Services\Tmdb;

use RuntimeException;

class TmdbException extends RuntimeException
{
    public static function requestFailed(string $endpoint, int $status, string $body): self
    {
        return new self("TMDB request to [{$endpoint}] failed with status {$status}: {$body}");
    }

    public static function connectionFailed(string $endpoint, string $message): self
    {
        return new self("TMDB request to [{$endpoint}] failed: {$message}");
    }
}
