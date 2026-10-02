<?php

namespace App\Services\Updates;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrates Docker-based application updates via the updater sidecar.
 */
class Updater
{
    private const HEALTH_CHECK_MAX_SECONDS = 300;

    private const HEALTH_CHECK_INTERVAL_SECONDS = 2;

    private const IMAGE_REPOSITORY = 'ghcr.io/krakero/screening-room';

    public function __construct(
        private readonly DockerCommands $docker,
        private readonly string $projectDir,
        private readonly string $projectName,
        private readonly string $serviceName,
        private readonly string $disk = 'updater',
    ) {}

    /**
     * Process a pending update request.
     */
    public function processRequest(): void
    {
        $storage = Storage::disk($this->disk);

        if (! $storage->exists('request.json')) {
            return;
        }

        // Move to processing to prevent concurrent execution
        $request = $this->readJson('request.json');
        $storage->move('request.json', 'request.processing.json');

        try {
            $this->executeUpdate($request);
        } finally {
            // Always clean up processing file
            $storage->delete('request.processing.json');
        }
    }

    /**
     * Write the heartbeat file.
     */
    public function writeHeartbeat(): void
    {
        $currentTag = $this->readCurrentTagFromEnv();

        $this->writeJson('heartbeat.json', [
            'at' => now()->toIso8601String(),
            'current_tag' => $currentTag,
            'updater_version' => config('app.version'),
        ]);
    }

    /**
     * Execute the update process.
     */
    private function executeUpdate(array $request): void
    {
        $requestId = $request['id'] ?? 'unknown';
        $tag = $request['tag'] ?? 'latest';
        $image = self::IMAGE_REPOSITORY.":{$tag}";

        $fromTag = $this->readCurrentTagFromEnv();

        // Step 1: Pull the new image
        $this->writeStatus([
            'id' => $requestId,
            'state' => 'pulling',
            'message' => "Pulling {$image}",
            'from_tag' => $fromTag,
            'to_tag' => $tag,
            'started_at' => now()->toIso8601String(),
        ]);

        $pullSucceeded = $this->docker->pull($image);
        $pullMessage = "Pulling {$image}";

        if (! $pullSucceeded) {
            if ($this->docker->imageExists($image)) {
                $pullMessage = "Pull failed; using local image {$image}";
            } else {
                $this->writeStatus([
                    'id' => $requestId,
                    'state' => 'failed',
                    'message' => "Failed to pull {$image} and image does not exist locally",
                    'from_tag' => $fromTag,
                    'to_tag' => $tag,
                    'finished_at' => now()->toIso8601String(),
                ]);

                return;
            }
        }

        // Step 2: Update .env and recreate service
        $this->writeStatus([
            'id' => $requestId,
            'state' => 'recreating',
            'message' => $pullMessage,
            'from_tag' => $fromTag,
            'to_tag' => $tag,
        ]);

        $this->docker->updateEnvTag($this->projectDir, $tag);

        if (! $this->docker->recreateService($this->projectDir, $this->projectName, $this->serviceName, $tag)) {
            $this->writeStatus([
                'id' => $requestId,
                'state' => 'failed',
                'message' => 'Failed to recreate service',
                'from_tag' => $fromTag,
                'to_tag' => $tag,
                'finished_at' => now()->toIso8601String(),
            ]);

            return;
        }

        // Step 2b: Verify the container is running the correct image
        $containerId = $this->docker->getContainerId($this->projectDir, $this->projectName, $this->serviceName);

        if ($containerId !== null) {
            $actualImage = $this->docker->containerImage($containerId);
            $expectedImage = self::IMAGE_REPOSITORY.":{$tag}";

            if ($actualImage !== null && ! str_ends_with($actualImage, ":{$tag}")) {
                // Image mismatch - trigger rollback immediately
                $this->writeStatus([
                    'id' => $requestId,
                    'state' => 'rolling_back',
                    'message' => "Recreated container is still on {$actualImage}",
                    'from_tag' => $fromTag,
                    'to_tag' => $tag,
                ]);

                // Restore previous tag
                $this->docker->updateEnvTag($this->projectDir, $fromTag);
                $this->docker->recreateService($this->projectDir, $this->projectName, $this->serviceName, $fromTag);

                $this->writeStatus([
                    'id' => $requestId,
                    'state' => 'rolled_back',
                    'message' => "Rolled back to {$fromTag}: Recreated container is still on {$actualImage}",
                    'from_tag' => $fromTag,
                    'to_tag' => $tag,
                    'finished_at' => now()->toIso8601String(),
                ]);

                return;
            }
        }

        // Step 3: Wait for health check
        $this->writeStatus([
            'id' => $requestId,
            'state' => 'waiting',
            'message' => 'Waiting for service to become healthy',
            'from_tag' => $fromTag,
            'to_tag' => $tag,
        ]);

        $healthy = $this->waitForHealthy();

        if ($healthy) {
            $this->writeStatus([
                'id' => $requestId,
                'state' => 'done',
                'message' => 'Update completed successfully',
                'from_tag' => $fromTag,
                'to_tag' => $tag,
                'finished_at' => now()->toIso8601String(),
            ]);

            return;
        }

        // Step 4: Rollback on failure
        $bootInfo = $this->readJson('boot.json');
        $errorMessage = $bootInfo['message'] ?? 'Service failed to become healthy';
        $dumpFilename = $bootInfo['dump'] ?? null;

        $this->writeStatus([
            'id' => $requestId,
            'state' => 'rolling_back',
            'message' => "Rolling back: {$errorMessage}",
            'from_tag' => $fromTag,
            'to_tag' => $tag,
            'dump' => $dumpFilename,
        ]);

        // Restore previous tag
        $this->docker->updateEnvTag($this->projectDir, $fromTag);
        $this->docker->recreateService($this->projectDir, $this->projectName, $this->serviceName, $fromTag);

        $this->writeStatus([
            'id' => $requestId,
            'state' => 'rolled_back',
            'message' => "Rolled back to {$fromTag}: {$errorMessage}",
            'from_tag' => $fromTag,
            'to_tag' => $tag,
            'dump' => $dumpFilename,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Wait for the container to become healthy.
     */
    private function waitForHealthy(): bool
    {
        $deadline = Carbon::now()->addSeconds(self::HEALTH_CHECK_MAX_SECONDS);

        while (Carbon::now()->isBefore($deadline)) {
            $containerId = $this->docker->getContainerId(
                $this->projectDir,
                $this->projectName,
                $this->serviceName
            );

            if ($containerId === null) {
                sleep(self::HEALTH_CHECK_INTERVAL_SECONDS);

                continue;
            }

            $health = $this->docker->getContainerHealth($containerId);

            if ($health === 'healthy') {
                return true;
            }

            // If container exited, fail immediately
            if (! $this->docker->isContainerRunning($containerId)) {
                return false;
            }

            sleep(self::HEALTH_CHECK_INTERVAL_SECONDS);
        }

        // Timeout
        return false;
    }

    /**
     * Read current tag from project .env file.
     */
    private function readCurrentTagFromEnv(): string
    {
        $envPath = $this->projectDir.'/.env';

        if (! file_exists($envPath)) {
            return 'latest';
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), 'APP_IMAGE_TAG=')) {
                return trim(substr($line, strlen('APP_IMAGE_TAG=')));
            }
        }

        return 'latest';
    }

    /**
     * Read and decode a JSON file.
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
     * Write status to status.json, merging with existing data.
     */
    private function writeStatus(array $data): void
    {
        $existing = $this->readJson('status.json') ?? [];
        $merged = array_merge($existing, $data);

        $this->writeJson('status.json', $merged);
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
