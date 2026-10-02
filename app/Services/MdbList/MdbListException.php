<?php

namespace App\Services\MdbList;

use RuntimeException;

class MdbListException extends RuntimeException
{
    private const RATE_LIMITED = 429;

    public function isRateLimited(): bool
    {
        return $this->getCode() === self::RATE_LIMITED;
    }

    public static function notConfigured(): self
    {
        return new self('MDBList is not configured.');
    }

    public static function rateLimited(): self
    {
        return new self('MDBList responded 429 Too Many Requests.', self::RATE_LIMITED);
    }

    public static function requestFailed(int $status, string $body): self
    {
        return new self("MDBList request failed with status {$status}: {$body}");
    }

    public static function connectionFailed(string $message): self
    {
        return new self("MDBList request failed: {$message}");
    }
}
