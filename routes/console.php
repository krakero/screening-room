<?php

use App\Console\Commands\RefreshRatings;
use App\Jobs\PollPlexHistory;
use App\Jobs\WarmDiscoverFeed;
use App\Services\Tmdb\TmdbClient;
use App\Support\IntegrationSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tmdb:refresh')->hourly()->withoutOverlapping();
Schedule::command('follows:abandon-stale')->daily()->withoutOverlapping();
Schedule::command('library:reconcile')->everySixHours()->withoutOverlapping();

Schedule::job(new PollPlexHistory)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->when(fn (): bool => app(IntegrationSettings::class)->configured('plex.url', 'plex.token'));

// Refreshes the cached TMDB payloads behind Discover (6h fresh / 24h stale) so the page never waits on TMDB.
Schedule::job(new WarmDiscoverFeed)
    ->everySixHours()
    ->withoutOverlapping()
    ->when(fn (): bool => app(TmdbClient::class)->token() !== null);

Schedule::command('notifications:episodes-airing-today')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->when(fn (): bool => app(IntegrationSettings::class)->configured('pushover.user_key', 'pushover.app_token'));

Schedule::command('plex:index')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->when(fn (): bool => app(IntegrationSettings::class)->configured('plex.url', 'plex.token'));

// After plex:index: re-checks the selected server's connection (local → remote → relay) when
// Plex discovery is in use, so a changed IP/port is picked up without waiting for a failed call.
Schedule::command('plex:recheck-connection')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->when(fn (): bool => app(IntegrationSettings::class)->configured('plex.url', 'plex.token'));

Schedule::command('plex:refresh-availability')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->when(fn (): bool => app(IntegrationSettings::class)->configured('plex.url', 'plex.token'));

// 05:30 UTC: after both the 03:00/03:30 Plex jobs above and local midnight in the default
// display timezone (America/New_York, UTC-4/-5), so the day's cache key has already rolled
// over by the time this warms it.
Schedule::command('upnext:warm')
    ->dailyAt('05:30')
    ->withoutOverlapping();

// MDBList free tier is 1,000 requests/day: 40 titles/hour (960/day) — never-checked first, then
// the stalest — with jobs spaced out. A 429 releases the job for 15 minutes instead of burning it.
Schedule::command('ratings:refresh', ['--limit' => RefreshRatings::HOURLY_LIMIT])
    ->hourly()
    ->withoutOverlapping()
    ->when(fn (): bool => app(IntegrationSettings::class)->configured('mdblist.api_key'));

Schedule::command('updates:check')
    ->daily()
    ->withoutOverlapping();
