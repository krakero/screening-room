<?php

namespace App\Enums;

/**
 * Groups qBittorrent's raw torrent states into the handful the UI cares about.
 */
enum TorrentState: string
{
    case Downloading = 'downloading';
    case Seeding = 'seeding';
    case Paused = 'paused';
    case Queued = 'queued';
    case Stalled = 'stalled';
    case Checking = 'checking';
    case Error = 'error';
    case Completed = 'completed';
    case Unknown = 'unknown';

    public static function fromApi(string $raw): self
    {
        return match ($raw) {
            'downloading', 'forcedDL', 'metaDL', 'forcedMetaDL', 'allocating' => self::Downloading,
            'uploading', 'forcedUP', 'stalledUP' => self::Seeding,
            'pausedDL', 'stoppedDL' => self::Paused,
            'pausedUP', 'stoppedUP' => self::Completed,
            'queuedDL', 'queuedUP' => self::Queued,
            'stalledDL' => self::Stalled,
            'checkingDL', 'checkingUP', 'checkingResumeData', 'moving' => self::Checking,
            'error', 'missingFiles' => self::Error,
            default => self::Unknown,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Downloading => 'Downloading',
            self::Seeding => 'Seeding',
            self::Paused => 'Paused',
            self::Queued => 'Queued',
            self::Stalled => 'Stalled',
            self::Checking => 'Checking',
            self::Error => 'Error',
            self::Completed => 'Completed',
            self::Unknown => 'Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Downloading => 'blue',
            self::Seeding => 'green',
            self::Paused => 'zinc',
            self::Queued => 'zinc',
            self::Stalled => 'amber',
            self::Checking => 'purple',
            self::Error => 'red',
            self::Completed => 'green',
            self::Unknown => 'zinc',
        };
    }
}
