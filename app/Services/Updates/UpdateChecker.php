<?php

namespace App\Services\Updates;

use App\Enums\UpdateChannel;
use App\Support\AppVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Checks GitHub for available updates.
 */
class UpdateChecker
{
    private const CACHE_TTL_SECONDS = 21600; // 6 hours

    private const GITHUB_RELEASES_URL = 'https://api.github.com/repos/krakero/screening-room/releases/latest';

    private const GITHUB_ACTIONS_URL = 'https://api.github.com/repos/krakero/screening-room/actions/workflows/docker.yml/runs?branch=main&event=push&status=success&per_page=1';

    /**
     * Check for available updates.
     *
     * @param  bool  $force  Bypass cache and force a fresh check
     */
    public function check(bool $force = false): ?AvailableUpdate
    {
        $current = AppVersion::current();
        $versionKey = $current->commit ?? $current->version;
        $cacheKey = "updates.available.{$current->channel->value}.{$versionKey}";

        if (! $force && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);

            if ($cached === false) {
                return null;
            }

            // Anything else unreadable (e.g. an object cached by an older version, which
            // unserializes as __PHP_Incomplete_Class) falls through to a fresh fetch.
            if (is_array($cached) && ($update = AvailableUpdate::fromArray($cached)) !== null) {
                return $this->nullIfInstalled($update, $current);
            }
        }

        $update = $this->fetchUpdate($current);

        if ($update !== null) {
            $update = $this->nullIfInstalled($update, $current);
        }

        // Only plain data is cached (`cache.serializable_classes` is false); `false` marks
        // "no update" so it's distinguishable from a missing entry.
        Cache::put($cacheKey, $update?->toArray() ?? false, self::CACHE_TTL_SECONDS);

        return $update;
    }

    /**
     * Return null when the given update describes the already-installed build.
     */
    private function nullIfInstalled(AvailableUpdate $update, VersionInfo $current): ?AvailableUpdate
    {
        if ($current->channel === UpdateChannel::Develop) {
            if ($current->commit !== null && $update->commit !== null && $current->commit === $update->commit) {
                return null;
            }
        } else {
            if (! $this->isNewerVersion($update->version, $current->version)) {
                return null;
            }
        }

        return $update;
    }

    /**
     * Fetch update information from GitHub.
     */
    private function fetchUpdate(VersionInfo $current): ?AvailableUpdate
    {
        if ($current->channel === UpdateChannel::Stable) {
            return $this->checkStableRelease($current);
        }

        return $this->checkDevelopCommit($current);
    }

    /**
     * Check for newer stable release via GitHub Releases API.
     */
    private function checkStableRelease(VersionInfo $current): ?AvailableUpdate
    {
        $response = Http::get(self::GITHUB_RELEASES_URL);

        if (! $response->successful()) {
            return null;
        }

        $release = $response->json();

        if (! isset($release['tag_name'], $release['name'], $release['html_url'], $release['published_at'])) {
            return null;
        }

        $latestVersion = ltrim($release['tag_name'], 'v');

        // Compare semantic versions
        if (! $this->isNewerVersion($latestVersion, $current->version)) {
            return null;
        }

        return new AvailableUpdate(
            version: $latestVersion,
            summary: $release['name'],
            url: $release['html_url'],
            published_at: $release['published_at'],
            channel: UpdateChannel::Stable,
        );
    }

    /**
     * Check for a newer develop build via GitHub Actions workflow runs.
     * Uses the latest successful Docker workflow run so a commit is only announced
     * once its image has been built and pushed.
     */
    private function checkDevelopCommit(VersionInfo $current): ?AvailableUpdate
    {
        $response = Http::get(self::GITHUB_ACTIONS_URL);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $run = $data['workflow_runs'][0] ?? null;

        if ($run === null) {
            return null;
        }

        if (! isset($run['head_sha'], $run['head_commit']['message'], $run['head_commit']['timestamp'])) {
            return null;
        }

        $sha = $run['head_sha'];

        // Current commit already matches the latest successful build
        if ($current->commit !== null && $current->commit === $sha) {
            return null;
        }

        $summary = explode("\n", $run['head_commit']['message'])[0];

        return new AvailableUpdate(
            version: 'develop-'.substr($sha, 0, 7),
            summary: $summary,
            url: 'https://github.com/krakero/screening-room/commit/'.$sha,
            published_at: $run['head_commit']['timestamp'],
            channel: UpdateChannel::Develop,
            commit: $sha,
        );
    }

    /**
     * Compare semantic versions (simple implementation for X.Y.Z format).
     */
    private function isNewerVersion(string $latest, string $current): bool
    {
        // Handle 'dev' and 'develop-*' versions
        if (str_starts_with($current, 'dev') || str_starts_with($current, 'develop-')) {
            return true;
        }

        return version_compare($latest, $current, '>');
    }
}
