<?php

namespace App\Support\Maintenance;

use App\Console\Commands\RefreshRatings;

enum MaintenanceTask: string
{
    case PlexIndex = 'plex-index';
    case PlexAvailability = 'plex-availability';
    case PlexConnection = 'plex-connection';
    case TmdbRefresh = 'tmdb-refresh';
    case LibraryReconcile = 'library-reconcile';
    case RatingsRefresh = 'ratings-refresh';
    case UpNextWarm = 'upnext-warm';
    case UpdatesCheck = 'updates-check';

    public function command(): string
    {
        return match ($this) {
            self::PlexIndex => 'plex:index',
            self::PlexAvailability => 'plex:refresh-availability',
            self::PlexConnection => 'plex:recheck-connection',
            self::TmdbRefresh => 'tmdb:refresh',
            self::LibraryReconcile => 'library:reconcile',
            self::RatingsRefresh => 'ratings:refresh',
            self::UpNextWarm => 'upnext:warm',
            self::UpdatesCheck => 'updates:check',
        };
    }

    /** @return array<string, mixed> */
    public function arguments(): array
    {
        return match ($this) {
            self::RatingsRefresh => ['--limit' => RefreshRatings::HOURLY_LIMIT],
            default => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PlexIndex => 'Plex library index',
            self::PlexAvailability => 'Plex availability refresh',
            self::PlexConnection => 'Plex connection recheck',
            self::TmdbRefresh => 'TMDB metadata refresh',
            self::LibraryReconcile => 'Reconcile library with Seerr',
            self::RatingsRefresh => 'Ratings refresh',
            self::UpNextWarm => 'Rebuild Up Next',
            self::UpdatesCheck => 'Check for updates',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PlexIndex => 'Re-reads your Plex libraries, including when items were added.',
            self::PlexAvailability => 'Re-checks which of your library titles are available on Plex.',
            self::PlexConnection => 'Re-runs Plex server connection selection (local → remote → relay) to pick the best available connection.',
            self::TmdbRefresh => 'Updates metadata, ratings, and watch providers for stale titles from TMDB.',
            self::LibraryReconcile => 'Syncs request and download statuses with Seerr, Sonarr, and Radarr to catch events missed by webhooks.',
            self::RatingsRefresh => 'Fetches the latest external ratings from MDBList for a batch of titles.',
            self::UpNextWarm => 'Rebuilds the Up Next and Calendar caches for all users.',
            self::UpdatesCheck => 'Checks for a new application release.',
        };
    }

    public function requiresPlex(): bool
    {
        return in_array($this, [self::PlexIndex, self::PlexAvailability, self::PlexConnection], true);
    }

    public function cacheKey(): string
    {
        return "maintenance.task.{$this->value}";
    }
}
