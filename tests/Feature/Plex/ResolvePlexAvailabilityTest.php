<?php

use App\Actions\Plex\ResolvePlexAvailability;
use App\Jobs\ResolveEpisodePlexAvailability;
use App\Models\Episode;
use App\Models\PlexItem;
use App\Models\PlexLibraryEpisode;
use App\Models\PlexLibraryItem;
use App\Models\Season;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
});

function plexAvailabilityFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/Fixtures/plex/{$name}.json")),
        true,
    );
}

test('forTitle returns null when Plex is not configured', function () {
    $title = Title::factory()->create();

    expect(app(ResolvePlexAvailability::class)->forTitle($title))->toBeNull();
});

test('forTitle finds and caches a movie from the local library index, with no HTTP calls', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    PlexLibraryItem::factory()->create([
        'plex_rating_key' => '501',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
    ]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $item = app(ResolvePlexAvailability::class)->forTitle($title);

    expect($item)->not->toBeNull()
        ->and($item->rating_key)->toBe('501')
        ->and($item->machine_identifier)->toBe('abc123def456')
        ->and($item->found())->toBeTrue()
        ->and($item->playUrl())->toBe('https://app.plex.tv/desktop/#!/server/abc123def456/details?key=%2Flibrary%2Fmetadata%2F501');

    expect(PlexItem::query()->where('plexable_type', 'title')->where('plexable_id', $title->id)->count())->toBe(1);

    Http::assertNothingSent();
});

test('forTitle matches a show by tmdb id and does not match a movie with the same tmdb id', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    PlexLibraryItem::factory()->create(['type' => 'movie', 'plex_rating_key' => '501', 'tmdb_id' => 603]);
    PlexLibraryItem::factory()->show()->create(['plex_rating_key' => '502', 'tmdb_id' => 603]);

    $show = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => null]);

    $item = app(ResolvePlexAvailability::class)->forTitle($show);

    expect($item->rating_key)->toBe('502');
});

test('forTitle returns not-found when nothing in the index matches, without caching a stale hit later', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $first = app(ResolvePlexAvailability::class)->forTitle($title);

    expect($first->found())->toBeFalse();

    // Once the index is synced with a match, forTitle must pick it up immediately — a stale
    // "not found" plex_items row from before the sync must never block the new lookup.
    PlexLibraryItem::factory()->create(['type' => 'movie', 'plex_rating_key' => '501', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $second = app(ResolvePlexAvailability::class)->forTitle($title);

    expect($second->id)->toBe($first->id)
        ->and($second->found())->toBeTrue()
        ->and($second->rating_key)->toBe('501');

    Http::assertNothingSent();
});

test('refreshEpisode resolves via the show plexItem and matches season/episode number', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::fake([
        '*plex.local:32400/library/metadata/501/allLeaves*' => Http::response(plexAvailabilityFixture('all_leaves_show')),
    ]);

    PlexLibraryItem::factory()->show()->create(['plex_rating_key' => '501', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($title, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->subDay(),
    ]);

    $item = app(ResolvePlexAvailability::class)->refreshEpisode($episode);

    expect($item->rating_key)->toBe('901')
        ->and($item->machine_identifier)->toBe('abc123def456');
});

test('refreshEpisode resolves from the local plex_library_episodes index with no HTTP call', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    PlexLibraryItem::factory()->show()->create(['plex_rating_key' => '501', 'machine_identifier' => 'abc123def456', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    PlexLibraryEpisode::factory()->create([
        'show_rating_key' => '501',
        'machine_identifier' => 'abc123def456',
        'season_number' => 1,
        'episode_number' => 1,
        'plex_rating_key' => '901',
    ]);

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($title, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->subDay(),
    ]);

    $item = app(ResolvePlexAvailability::class)->refreshEpisode($episode);

    expect($item->rating_key)->toBe('901')
        ->and($item->machine_identifier)->toBe('abc123def456');

    Http::assertNothingSent();
});

test('refreshEpisode falls back to a live lookup when the show is indexed but the episode is not', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::fake([
        '*plex.local:32400/library/metadata/501/allLeaves*' => Http::response(plexAvailabilityFixture('all_leaves_show')),
    ]);

    PlexLibraryItem::factory()->show()->create(['plex_rating_key' => '501', 'machine_identifier' => 'abc123def456', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($title, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->subDay(),
    ]);

    $item = app(ResolvePlexAvailability::class)->refreshEpisode($episode);

    expect($item->rating_key)->toBe('901');

    Http::assertSentCount(1);
});

test('refreshEpisode returns an unfound item when the show is not on Plex', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($title, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->subDay(),
    ]);

    $item = app(ResolvePlexAvailability::class)->refreshEpisode($episode);

    expect($item->found())->toBeFalse()
        ->and($item->playUrl())->toBeNull();

    Http::assertNothingSent();
});

test('forEpisode never calls Plex: it reads a cached row regardless of age, or queues a resolve job and returns null', function () {
    Queue::fake();
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($title, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 1]);

    // Nothing cached yet: null, and a resolve job is queued.
    expect(app(ResolvePlexAvailability::class)->forEpisode($episode))->toBeNull();
    Queue::assertPushed(ResolveEpisodePlexAvailability::class, fn (ResolveEpisodePlexAvailability $job): bool => $job->episode->is($episode));

    // A stale cached row is used as-is, with no HTTP call and no job queued.
    Queue::fake();
    $episode->plexItem()->create(['rating_key' => '901', 'machine_identifier' => 'abc123def456', 'checked_at' => now()->subDays(30)]);

    $item = app(ResolvePlexAvailability::class)->forEpisode($episode);

    expect($item->rating_key)->toBe('901');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('forEpisode returns null without queuing when Plex is not configured', function () {
    Queue::fake();

    $episode = Episode::factory()->create();

    expect(app(ResolvePlexAvailability::class)->forEpisode($episode))->toBeNull();
    Queue::assertNothingPushed();
});

test('forEpisodes batches the cache lookup for many episodes in one query and queues jobs only for the missing ones', function () {
    Queue::fake();
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $cached = Episode::factory()->for($title, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 1]);
    $missing = Episode::factory()->for($title, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 2]);
    $cached->plexItem()->create(['rating_key' => '901', 'machine_identifier' => 'abc123def456', 'checked_at' => now()->subDays(30)]);

    $results = app(ResolvePlexAvailability::class)->forEpisodes(collect([$cached, $missing]));

    expect($results->get($cached->id)?->rating_key)->toBe('901')
        ->and($results->has($missing->id))->toBeFalse();

    Queue::assertPushed(ResolveEpisodePlexAvailability::class, 1);
    Queue::assertPushed(ResolveEpisodePlexAvailability::class, fn (ResolveEpisodePlexAvailability $job): bool => $job->episode->is($missing));
    Http::assertNothingSent();
});

test('isPendingForEpisode is true only when Plex is configured and no row is cached yet', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($title, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 1]);

    expect(app(ResolvePlexAvailability::class)->isPendingForEpisode($episode))->toBeFalse();

    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    expect(app(ResolvePlexAvailability::class)->isPendingForEpisode($episode))->toBeTrue();

    $episode->plexItem()->create(['rating_key' => null, 'machine_identifier' => null, 'checked_at' => now()]);

    expect(app(ResolvePlexAvailability::class)->isPendingForEpisode($episode))->toBeFalse();
});

test('refreshEpisodesForShow resolves every stale episode of a show from the local index with zero HTTP calls', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    PlexLibraryItem::factory()->show()->create(['plex_rating_key' => '501', 'machine_identifier' => 'abc123def456', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    PlexLibraryEpisode::factory()->create(['show_rating_key' => '501', 'machine_identifier' => 'abc123def456', 'season_number' => 1, 'episode_number' => 1, 'plex_rating_key' => '901']);
    PlexLibraryEpisode::factory()->create(['show_rating_key' => '501', 'machine_identifier' => 'abc123def456', 'season_number' => 1, 'episode_number' => 2, 'plex_rating_key' => '902']);

    $show = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episodes = collect([
        Episode::factory()->for($show, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 1]),
        Episode::factory()->for($show, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 2]),
    ]);

    $results = app(ResolvePlexAvailability::class)->refreshEpisodesForShow($show, $episodes);

    expect($results->get($episodes[0]->id)['outcome'])->toBe('found')
        ->and($results->get($episodes[0]->id)['item']->rating_key)->toBe('901')
        ->and($results->get($episodes[1]->id)['outcome'])->toBe('found')
        ->and($results->get($episodes[1]->id)['item']->rating_key)->toBe('902');

    Http::assertNothingSent();
});

test('refreshEpisodesForShow makes at most one live call for a whole show, for episodes missing from the index', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);

    Http::fake([
        '*plex.local:32400/library/metadata/501/allLeaves*' => Http::response(plexAvailabilityFixture('all_leaves_show')),
    ]);

    PlexLibraryItem::factory()->show()->create(['plex_rating_key' => '501', 'machine_identifier' => 'abc123def456', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    // No plex_library_episodes rows: every episode is "missing from the index".

    $show = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episodes = collect([
        Episode::factory()->for($show, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 1]),
        Episode::factory()->for($show, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 2]),
        Episode::factory()->for($show, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 99]),
    ]);

    $results = app(ResolvePlexAvailability::class)->refreshEpisodesForShow($show, $episodes);

    expect($results->get($episodes[0]->id)['outcome'])->toBe('found')
        ->and($results->get($episodes[0]->id)['item']->rating_key)->toBe('901')
        ->and($results->get($episodes[1]->id)['outcome'])->toBe('found')
        ->and($results->get($episodes[1]->id)['item']->rating_key)->toBe('902')
        ->and($results->get($episodes[2]->id)['outcome'])->toBe('not_found');

    Http::assertSentCount(1);
});

test('refreshEpisodesForShow skips episodes whose cached row is already fresh, unless forced', function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    PlexLibraryItem::factory()->show()->create(['plex_rating_key' => '501', 'machine_identifier' => 'abc123def456', 'tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $show = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create(['season_number' => 1, 'episode_number' => 1]);
    $episode->plexItem()->create(['rating_key' => '901', 'machine_identifier' => 'abc123def456', 'checked_at' => now()]);

    $results = app(ResolvePlexAvailability::class)->refreshEpisodesForShow($show, collect([$episode]));

    expect($results->get($episode->id)['outcome'])->toBe('skipped_fresh');

    Http::assertNothingSent();
});
