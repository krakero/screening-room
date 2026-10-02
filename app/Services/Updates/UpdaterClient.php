<?php

namespace App\Services\Updates;

use App\Enums\UpdateChannel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client for communicating with the updater sidecar via the shared file contract.
 */
class UpdaterClient
{
    private const HEARTBEAT_TTL_SECONDS = 30;

    public function __construct(
        private readonly string $disk = 'updater',
    ) {}

    /**
     * Check if the updater sidecar is available (heartbeat age < 30s).
     */
    public function isAvailable(): bool
    {
        $heartbeat = $this->heartbeat();

        if ($heartbeat === null || ! isset($heartbeat['at'])) {
            return false;
        }

        try {
            $at = Carbon::parse($heartbeat['at']);

            return $at->diffInSeconds(now()) < self::HEARTBEAT_TTL_SECONDS;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Read the updater heartbeat file.
     */
    public function heartbeat(): ?array
    {
        return $this->readJson('heartbeat.json');
    }

    /**
     * Read the current update status.
     */
    public function status(): ?array
    {
        return $this->readJson('status.json');
    }

    /**
     * Request an update to the given channel.
     *
     * @throws RuntimeException if a request is already pending
     */
    public function requestUpdate(UpdateChannel $channel, string $action): string
    {
        if ($this->hasPendingRequest()) {
            throw new RuntimeException('An update request is already pending');
        }

        $id = (string) Str::uuid();
        $request = [
            'id' => $id,
            'action' => $action,
            'tag' => $channel->tag(),
            'requested_at' => now()->toIso8601String(),
        ];

        $this->writeJson('request.json', $request);

        return $id;
    }

    /**
     * Get the current image tag from the heartbeat.
     */
    public function currentTag(): ?string
    {
        $heartbeat = $this->heartbeat();

        return $heartbeat['current_tag'] ?? null;
    }

    /**
     * List pre-update database dumps.
     *
     * @return array<int, array{filename: string, size: int, created_at: string}>
     */
    public function preUpdateDumps(): array
    {
        $backupsDir = storage_path('app/backups');
        $dumps = [];

        if (! is_dir($backupsDir)) {
            return [];
        }

        $files = glob($backupsDir.'/pre-update-*.sql.gz') ?: [];

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $dumps[] = [
                'filename' => basename($file),
                'size' => filesize($file),
                'created_at' => Carbon::createFromTimestamp(filemtime($file))->toIso8601String(),
            ];
        }

        // Sort newest first
        usort($dumps, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $dumps;
    }

    /**
     * Check if a request is pending (request.json or request.processing.json exists).
     */
    private function hasPendingRequest(): bool
    {
        return Storage::disk($this->disk)->exists('request.json')
            || Storage::disk($this->disk)->exists('request.processing.json');
    }

    /**
     * Read and decode a JSON file, returning null if it doesn't exist or is invalid.
     */
    private function readJson(string $path): ?array
    {
        $storage = Storage::disk($this->disk);

        if (! $storage->exists($path)) {
            return null;
        }

        try {
            $contents = $storage->get($path);
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Write data as JSON to a file.
     */
    private function writeJson(string $path, array $data): void
    {
        Storage::disk($this->disk)->put(
            $path,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}
