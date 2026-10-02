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

    private const GITHUB_COMMITS_URL = 'https://api.github.com/repos/krakero/screening-room/commits/main';

    /**
     * Check for available updates.
     *
     * @param  bool  $force  Bypass cache and force a fresh check
     */
    public function check(bool $force = false): ?AvailableUpdate
    {
        $current = AppVersion::current();
        $cacheKey = "updates.available.{$current->channel->value}";

        if ($force) {
            Cache::forget($cacheKey);
        }

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchUpdate($current)
        );
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
     * Check for newer develop commit via GitHub Commits API.
     */
    private function checkDevelopCommit(VersionInfo $current): ?AvailableUpdate
    {
        $response = Http::get(self::GITHUB_COMMITS_URL);

        if (! $response->successful()) {
            return null;
        }

        $commit = $response->json();

        if (! isset($commit['sha'], $commit['commit']['message'], $commit['html_url'], $commit['commit']['committer']['date'])) {
            return null;
        }

        $latestSha = $commit['sha'];

        // If current commit matches latest, no update available
        if ($current->commit !== null && $current->commit === $latestSha) {
            return null;
        }

        // Extract first line of commit message as summary
        $message = $commit['commit']['message'];
        $summary = explode("\n", $message)[0];

        return new AvailableUpdate(
            version: 'develop-'.substr($latestSha, 0, 7),
            summary: $summary,
            url: $commit['html_url'],
            published_at: $commit['commit']['committer']['date'],
            channel: UpdateChannel::Develop,
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
