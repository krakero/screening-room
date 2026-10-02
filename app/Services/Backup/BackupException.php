<?php

namespace App\Services\Backup;

use RuntimeException;

class BackupException extends RuntimeException
{
    public static function passwordMissing(): self
    {
        return new self('Set a backup password (at least 8 characters) before creating a backup.');
    }

    public static function folderNotWritable(string $directory): self
    {
        return new self("The backup folder [{$directory}] does not exist or is not writable.");
    }

    public static function invalidFilename(string $filename): self
    {
        return new self("[{$filename}] is not a valid backup file name.");
    }

    public static function notFound(string $filename): self
    {
        return new self("Backup [{$filename}] was not found.");
    }

    public static function writeFailed(string $message): self
    {
        return new self("The backup could not be written: {$message}");
    }
}
