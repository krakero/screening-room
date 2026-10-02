<?php

namespace App\Services\Updates;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Real implementation of DockerCommands using Symfony Process.
 */
class DockerCommandsImplementation implements DockerCommands
{
    private const TIMEOUT_SECONDS = 300;

    public function pull(string $image): bool
    {
        $process = new Process(['docker', 'pull', '--quiet', $image]);
        $process->setTimeout(600);
        $process->setIdleTimeout(120);

        try {
            $process->mustRun();

            return true;
        } catch (ExceptionInterface $e) {
            // Ensure the process is stopped on timeout
            if ($process->isRunning()) {
                $process->stop(5);
            }

            return false;
        }
    }

    public function updateEnvTag(string $projectDir, string $tag): void
    {
        $envPath = $projectDir.'/.env';
        $lines = file_exists($envPath) ? file($envPath, FILE_IGNORE_NEW_LINES) : [];
        $updated = false;

        foreach ($lines as $index => $line) {
            if (str_starts_with(trim($line), 'APP_IMAGE_TAG=')) {
                $lines[$index] = "APP_IMAGE_TAG={$tag}";
                $updated = true;
                break;
            }
        }

        if (! $updated) {
            $lines[] = "APP_IMAGE_TAG={$tag}";
        }

        file_put_contents($envPath, implode("\n", $lines)."\n");
    }

    public function recreateService(string $projectDir, string $projectName, string $serviceName, string $tag): bool
    {
        // Global compose flags come before the subcommand; --env-file only when the file exists
        $envFile = $projectDir.'/.env';

        $command = [
            'docker', 'compose',
            '--project-directory', $projectDir,
            '-p', $projectName,
            ...(file_exists($envFile) ? ['--env-file', $envFile] : []),
            'up', '-d', '--no-deps', '--pull', 'never',
            $serviceName,
        ];

        // Pass APP_IMAGE_TAG explicitly via environment to override any inherited value
        $env = array_merge(getenv(), ['APP_IMAGE_TAG' => $tag]);

        $process = new Process($command, null, $env);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();

            return true;
        } catch (ExceptionInterface) {
            return false;
        }
    }

    public function getContainerId(string $projectDir, string $projectName, string $serviceName): ?string
    {
        $process = new Process([
            'docker', 'compose',
            '--project-directory', $projectDir,
            '-p', $projectName,
            'ps', '-q', $serviceName,
        ]);

        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output !== '' ? $output : null;
    }

    public function getContainerHealth(string $containerId): ?string
    {
        $process = new Process([
            'docker', 'inspect',
            '-f', '{{.State.Health.Status}}',
            $containerId,
        ]);

        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        // If health is not enabled, inspect returns '<no value>'
        if ($output === '<no value>' || $output === '') {
            // Fall back to checking if container is running
            return $this->isContainerRunning($containerId) ? 'healthy' : null;
        }

        return $output;
    }

    public function isContainerRunning(string $containerId): bool
    {
        $process = new Process([
            'docker', 'inspect',
            '-f', '{{.State.Running}}',
            $containerId,
        ]);

        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        return trim($process->getOutput()) === 'true';
    }

    public function imageExists(string $image): bool
    {
        $process = new Process([
            'docker', 'image', 'inspect',
            $image,
        ]);

        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    public function containerImage(string $containerId): ?string
    {
        $process = new Process([
            'docker', 'inspect',
            '-f', '{{.Config.Image}}',
            $containerId,
        ]);

        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output !== '' ? $output : null;
    }

    /**
     * Run a Docker command with timeout.
     */
    private function runCommand(array $command): bool
    {
        $process = new Process($command);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();

            return true;
        } catch (ExceptionInterface) {
            return false;
        }
    }
}
