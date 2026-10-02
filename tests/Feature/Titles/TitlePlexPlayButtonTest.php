<?php

use App\Jobs\ResolveEpisodePlexAvailability;
use App\Models\Episode;
use App\Models\PlexLibraryItem;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('the movie title page has no Watch on Plex button when Plex is not configured', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertDontSee('Watch on Plex');
});

test('the movie title page shows a Watch on Plex button when the movie is found on the server', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::preventStrayRequests();

    PlexLibraryItem::factory()->create([
        'plex_rating_key' => '501',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
    ]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('Watch on Plex');
    $response->assertDontSee('Play on Plex');
    $response->assertSee('https://app.plex.tv/desktop/#!/server/abc123def456/details?key=%2Flibrary%2Fmetadata%2F501', false);
});

test('the movie title page has no Watch on Plex button when the movie is not found on the server', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::preventStrayRequests();

    $title = Title::factory()->movie()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertDontSee('Watch on Plex');
});

test('the show title page shows a "Checking Plex…" pending state and queues a resolve job when the next episode has no cached row, with no Http call', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::preventStrayRequests();
    Queue::fake();

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($title, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->subDay(),
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(__('Checking Plex…'));
    $response->assertDontSee('Watch on Plex');

    // The pending icon must spin (animate-spin), not sit static.
    $content = $response->getContent();
    $checkingPos = strpos($content, __('Checking Plex…'));
    expect($checkingPos)->not->toBeFalse();
    expect(substr($content, max(0, $checkingPos - 1500), 1500))->toContain('animate-spin');

    Queue::assertPushed(ResolveEpisodePlexAvailability::class, fn (ResolveEpisodePlexAvailability $job): bool => $job->episode->is($episode));
    Http::assertNothingSent();
});

test('the show title page shows the Watch on Plex button from an already-cached (even stale) row, with no Http call', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::preventStrayRequests();

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($title, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->subDay(),
    ]);
    $episode->plexItem()->create(['rating_key' => '901', 'machine_identifier' => 'abc123def456', 'checked_at' => now()->subDays(30)]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertDontSee(__('Checking Plex…'));
    $response->assertSee('Watch on Plex');
    $response->assertDontSee('Play on Plex');
    $response->assertSee('https://app.plex.tv/desktop/#!/server/abc123def456/details?key=%2Flibrary%2Fmetadata%2F901', false);
});

test('the season page shows a "Checking Plex…" pending state and queues resolve jobs for aired episodes with no cached row, with no Http call', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::preventStrayRequests();
    Queue::fake();

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($title, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->subDay(),
    ]);

    $response = $this->get(route('titles.seasons.show', [$title, $season->season_number]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee(__('Checking Plex…'));

    Queue::assertPushed(ResolveEpisodePlexAvailability::class, fn (ResolveEpisodePlexAvailability $job): bool => $job->episode->is($episode));
    Http::assertNothingSent();
});

test('the season page shows a per-episode Watch on Plex button from an already-cached row, with no Http call', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::preventStrayRequests();

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($title, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->subDay(),
    ]);
    $episode->plexItem()->create(['rating_key' => '901', 'machine_identifier' => 'abc123def456', 'checked_at' => now()->subDays(30)]);

    $response = $this->get(route('titles.seasons.show', [$title, $season->season_number]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertDontSee(__('Checking Plex…'));
    $response->assertSee('Watch on Plex');
    $response->assertDontSee('Play on Plex');
    $response->assertSee('https://app.plex.tv/desktop/#!/server/abc123def456/details?key=%2Flibrary%2Fmetadata%2F901', false);
});

test('the season page has no per-episode Watch on Plex button, and queues nothing, for unaired episodes', function () {
    $this->actingAs(User::factory()->create());

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::preventStrayRequests();
    Queue::fake();

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    Episode::factory()->for($title, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->addWeek(),
    ]);

    $response = $this->get(route('titles.seasons.show', [$title, $season->season_number]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertDontSee(__('Checking Plex…'));

    Queue::assertNotPushed(ResolveEpisodePlexAvailability::class);
});
