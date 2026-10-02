<?php

namespace App\Services\Qbittorrent;

use RuntimeException;

class QbittorrentException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('qBittorrent is not configured.');
    }

    public static function invalidCredentials(): self
    {
        return new self('qBittorrent rejected the username or password.');
    }

    public static function requestFailed(string $endpoint, int $status, string $body): self
    {
        return new self("qBittorrent request to [{$endpoint}] failed with status {$status}: {$body}");
    }

    public static function connectionFailed(string $endpoint, string $message): self
    {
        return new self("qBittorrent request to [{$endpoint}] failed: {$message}");
    }
}
