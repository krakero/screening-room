<?php

use App\Models\Episode;
use App\Models\PlexItem;
use App\Models\PlexLibraryItem;
use App\Models\Season;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

function refreshFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/Fixtures/plex/{$name}.json")),
        true,
    );
}

test('it does nothing when Plex is not configured', function () {
    Http::preventStrayRequests();

    $this->artisan('plex:refresh-availability')->assertExitCode(0);

    Http::assertNothingSent();
});

test('it prints "Nothing to refresh" and exits successfully when there is nothing stale', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    $this->artisan('plex:refresh-availability')
        ->expectsOutputToContain('Nothing to refresh.')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('it re-resolves only stale PlexItems by default, from the local index with no HTTP calls, and prints a summary', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    $fresh = Title::factory()->create(['tmdb_id' => 1, 'imdb_id' => 'tt0000001']);
    $fresh->plexItem()->create(['rating_key' => '111', 'machine_identifier' => 'abc123def456', 'checked_at' => now()]);

    $stale = Title::factory()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $stale->plexItem()->create(['rating_key' => null, 'machine_identifier' => null, 'checked_at' => now()->subDays(2)]);

    PlexLibraryItem::factory()->create(['type' => 'movie', 'plex_rating_key' => '501', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $this->artisan('plex:refresh-availability')
        ->expectsOutputToContain('Checked')
        ->expectsOutputToContain('Found')
        ->expectsOutputToContain('Not found')
        ->expectsOutputToContain('Errors')
        ->expectsOutputToContain('Elapsed')
        ->assertExitCode(0);

    expect($fresh->plexItem()->first()->rating_key)->toBe('111');
    expect($stale->plexItem()->first()->rating_key)->toBe('501');

    Http::assertNothingSent();
});

test('--force re-resolves every cached item regardless of freshness', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    $title = Title::factory()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $title->plexItem()->create(['rating_key' => '999', 'machine_identifier' => 'stale-server', 'checked_at' => now()]);

    PlexLibraryItem::factory()->create(['type' => 'movie', 'plex_rating_key' => '501', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $this->artisan('plex:refresh-availability', ['--force' => true])->assertExitCode(0);

    expect($title->plexItem()->first()->rating_key)->toBe('501');
});

test('it refreshes cached episodes via the resolver too', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    PlexLibraryItem::factory()->show()->create(['plex_rating_key' => '501', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $show = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $show->plexItem()->create(['rating_key' => '501', 'machine_identifier' => 'abc123def456', 'checked_at' => now()]);

    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->subDay(),
    ]);
    $episode->plexItem()->create(['rating_key' => null, 'machine_identifier' => null, 'checked_at' => now()->subDays(2)]);

    Http::fake([
        '*plex.local:32400/library/metadata/501/allLeaves*' => Http::response(refreshFixture('all_leaves_show')),
    ]);

    $this->artisan('plex:refresh-availability')->assertExitCode(0);

    expect($episode->plexItem()->first()->rating_key)->toBe('901');
    expect(PlexItem::count())->toBe(2);
    Http::assertSentCount(1);
});

test('it groups stale episodes by show and makes at most one live call per show, not one per episode', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    PlexLibraryItem::factory()->show()->create(['plex_rating_key' => '501', 'machine_identifier' => 'abc123def456', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $show = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $show->plexItem()->create(['rating_key' => '501', 'machine_identifier' => 'abc123def456', 'checked_at' => now()]);

    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);

    $episodeOne = Episode::factory()->for($show, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 1]);
    $episodeOne->plexItem()->create(['rating_key' => null, 'machine_identifier' => null, 'checked_at' => now()->subDays(2)]);

    $episodeTwo = Episode::factory()->for($show, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 2]);
    $episodeTwo->plexItem()->create(['rating_key' => null, 'machine_identifier' => null, 'checked_at' => now()->subDays(2)]);

    Http::fake([
        '*plex.local:32400/library/metadata/501/allLeaves*' => Http::response(refreshFixture('all_leaves_show')),
    ]);

    $this->artisan('plex:refresh-availability')->assertExitCode(0);

    expect($episodeOne->plexItem()->first()->rating_key)->toBe('901')
        ->and($episodeTwo->plexItem()->first()->rating_key)->toBe('902');

    Http::assertSentCount(1);
});
