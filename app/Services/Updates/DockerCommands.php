<?php

namespace App\Services\Updates;

interface DockerCommands
{
    /**
     * Pull a Docker image.
     *
     * @param  string  $image  The image to pull (e.g., 'ghcr.io/krakero/screening-room:latest')
     * @return bool True if successful
     */
    public function pull(string $image): bool;

    /**
     * Update the APP_IMAGE_TAG in the project's .env file.
     *
     * @param  string  $projectDir  The project directory containing .env
     * @param  string  $tag  The image tag to set
     */
    public function updateEnvTag(string $projectDir, string $tag): void;

    /**
     * Recreate the app service using docker compose.
     *
     * @param  string  $projectDir  The project directory
     * @param  string  $projectName  The compose project name
     * @param  string  $serviceName  The service to recreate
     * @param  string  $tag  The image tag to use (passed explicitly via environment)
     * @return bool True if successful
     */
    public function recreateService(string $projectDir, string $projectName, string $serviceName, string $tag): bool;

    /**
     * Get the container ID for a service.
     *
     * @param  string  $projectDir  The project directory
     * @param  string  $projectName  The compose project name
     * @param  string  $serviceName  The service name
     * @return string|null The container ID, or null if not found
     */
    public function getContainerId(string $projectDir, string $projectName, string $serviceName): ?string;

    /**
     * Inspect container health status.
     *
     * @param  string  $containerId  The container ID
     * @return string|null Health status ('healthy', 'unhealthy', 'starting', etc.) or null if not found
     */
    public function getContainerHealth(string $containerId): ?string;

    /**
     * Check if a container is running.
     *
     * @param  string  $containerId  The container ID
     * @return bool True if the container is running
     */
    public function isContainerRunning(string $containerId): bool;

    /**
     * Check if a Docker image exists locally.
     *
     * @param  string  $image  The image to check (e.g., 'ghcr.io/krakero/screening-room:latest')
     * @return bool True if the image exists locally
     */
    public function imageExists(string $image): bool;

    /**
     * Get the image a container is running.
     *
     * @param  string  $containerId  The container ID
     * @return string|null The full image name with tag, or null if not found
     */
    public function containerImage(string $containerId): ?string;
}
