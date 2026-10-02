<?php

use App\Enums\TitleType;
use App\Models\Play;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('it fails when plex is not configured', function () {
    $this->artisan('plex:poll')
        ->assertFailed();
});

test('it records plays from history since the given option', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
    ]);

    Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    $history = json_decode(file_get_contents(base_path('tests/Fixtures/plex/history_all.json')), true);
    $history['MediaContainer']['Metadata'] = [$history['MediaContainer']['Metadata'][0]];
    $history['MediaContainer']['Metadata'][0]['viewedAt'] = Carbon::now()->subMinutes(5)->timestamp;

    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response($history),
        '*plex.local:32400/library/metadata/501*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/plex/metadata_movie.json')), true)),
    ]);

    $this->artisan('plex:poll --since="20 minutes ago"')
        ->assertSuccessful();

    expect(Play::count())->toBe(1);
});

test('a dry run does not record plays', function () {
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'http://plex.local:32400',
        'plex.token' => 'plex-token',
    ]);

    Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    $history = json_decode(file_get_contents(base_path('tests/Fixtures/plex/history_all.json')), true);
    $history['MediaContainer']['Metadata'] = [$history['MediaContainer']['Metadata'][0]];
    $history['MediaContainer']['Metadata'][0]['viewedAt'] = Carbon::now()->subMinutes(5)->timestamp;

    Http::fake([
        '*plex.local:32400/status/sessions/history/all*' => Http::response($history),
        '*plex.local:32400/library/metadata/501*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/plex/metadata_movie.json')), true)),
    ]);

    $this->artisan('plex:poll --since="20 minutes ago" --dry-run')
        ->assertSuccessful();

    expect(Play::count())->toBe(0);
});
