<?php

use App\Models\PlexLibraryItem;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

function indexCommandFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/Fixtures/plex/{$name}.json")),
        true,
    );
}

test('it does nothing when Plex is not configured', function () {
    Http::preventStrayRequests();

    $this->artisan('plex:index')->assertExitCode(0);

    Http::assertNothingSent();
});

test('it syncs the index and records success status', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(indexCommandFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => [
            ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ]]]),
        '*plex.local:32400/library/sections/1/all*' => Http::response(indexCommandFixture('library_section_movies')),
    ]);

    $this->artisan('plex:index')->assertExitCode(0);

    expect(PlexLibraryItem::count())->toBe(1);

    $status = app(IntegrationSettings::class)->get('plex.library_index');

    expect($status['status'])->toBe('success')
        ->and($status['items_indexed'])->toBe(1);
});

test('it prints a progress bar and a summary of the run', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(indexCommandFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => [
            ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ]]]),
        '*plex.local:32400/library/sections/1/all*' => Http::response(indexCommandFixture('library_section_movies')),
    ]);

    $this->artisan('plex:index')
        ->assertExitCode(0)
        ->expectsOutputToContain('Sections')
        ->expectsOutputToContain('Items indexed')
        ->expectsOutputToContain('Episodes indexed')
        ->expectsOutputToContain('Removed')
        ->expectsOutputToContain('Titles refreshed')
        ->expectsOutputToContain('Elapsed');
});

test('it warns and skips cleanup in the output when the run looks incomplete', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    PlexLibraryItem::factory()->create([
        'plex_rating_key' => 'existing',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 111111,
        'indexed_at' => now()->subWeek(),
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(indexCommandFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => []]]),
    ]);

    $this->artisan('plex:index')
        ->assertExitCode(0)
        ->expectsOutputToContain('Skipped removing stale library rows');

    expect(PlexLibraryItem::query()->where('plex_rating_key', 'existing')->exists())->toBeTrue();
});

test('--force removes stale rows even when the run looks incomplete', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    PlexLibraryItem::factory()->create([
        'plex_rating_key' => 'existing',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 111111,
        'indexed_at' => now()->subWeek(),
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(indexCommandFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => []]]),
    ]);

    $this->artisan('plex:index --force')->assertExitCode(0);

    expect(PlexLibraryItem::query()->where('plex_rating_key', 'existing')->exists())->toBeFalse();
});

test('it records failure status when the sync throws', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(null, 500),
    ]);

    $this->artisan('plex:index')->assertExitCode(1);

    $status = app(IntegrationSettings::class)->get('plex.library_index');

    expect($status['status'])->toBe('failed');
});
