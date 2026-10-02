<?php

namespace App\Services\Plex;

use RuntimeException;

class PlexException extends RuntimeException
{
    public static function requestFailed(string $endpoint, int $status, string $body): self
    {
        return new self("Plex request to [{$endpoint}] failed with status {$status}: {$body}");
    }

    public static function connectionFailed(string $endpoint, string $message): self
    {
        if (self::looksLikeTlsFailure($message)) {
            $message .= ' Use http://<ip>:32400 on your LAN, or your https://…plex.direct:32400 address — Plex\'s certificate is only valid for *.plex.direct hostnames.';
        }

        return new self("Plex request to [{$endpoint}] failed: {$message}");
    }

    private static function looksLikeTlsFailure(string $message): bool
    {
        return str_contains($message, 'cURL error 60') || str_contains(strtolower($message), 'ssl certificate');
    }
}
