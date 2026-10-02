<?php

namespace App\Services\Qbittorrent;

use App\Enums\TorrentState;
use Carbon\CarbonImmutable;

final readonly class Torrent
{
    /** qBittorrent reports this ETA (100 days) when the time remaining is unknown. */
    private const ETA_INFINITY = 8640000;

    public function __construct(
        public string $hash,
        public string $name,
        public TorrentState $state,
        public float $progress,
        public int $size,
        public int $downloaded,
        public int $dlspeed,
        public int $upspeed,
        public ?int $eta,
        public ?string $category,
        public CarbonImmutable $addedOn,
        public int $numSeeds,
        public int $numLeechs,
        public float $ratio,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromApi(array $row): self
    {
        $eta = isset($row['eta']) ? (int) $row['eta'] : null;
        $category = (string) ($row['category'] ?? '');

        return new self(
            hash: (string) ($row['hash'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            state: TorrentState::fromApi((string) ($row['state'] ?? '')),
            progress: (float) ($row['progress'] ?? 0),
            size: (int) ($row['size'] ?? $row['total_size'] ?? 0),
            downloaded: (int) ($row['downloaded'] ?? 0),
            dlspeed: (int) ($row['dlspeed'] ?? 0),
            upspeed: (int) ($row['upspeed'] ?? 0),
            eta: $eta === null || $eta >= self::ETA_INFINITY ? null : $eta,
            category: $category === '' ? null : $category,
            addedOn: CarbonImmutable::createFromTimestamp((int) ($row['added_on'] ?? 0)),
            numSeeds: (int) ($row['num_seeds'] ?? 0),
            numLeechs: (int) ($row['num_leechs'] ?? 0),
            ratio: (float) ($row['ratio'] ?? 0),
        );
    }

    public function percent(): int
    {
        return (int) max(0, min(100, floor($this->progress * 100)));
    }
}
