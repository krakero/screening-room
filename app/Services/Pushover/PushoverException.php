<?php

namespace App\Services\Pushover;

use RuntimeException;

class PushoverException extends RuntimeException
{
    public static function requestFailed(int $status, string $body): self
    {
        return new self("Pushover request failed with status {$status}: {$body}");
    }

    public static function connectionFailed(string $message): self
    {
        return new self("Pushover request failed: {$message}");
    }
}
