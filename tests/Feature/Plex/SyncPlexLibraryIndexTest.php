<?php

use App\Actions\Plex\SyncPlexLibraryIndex;
use App\Enums\TitleType;
use App\Models\PlexLibraryEpisode;
use App\Models\PlexLibraryItem;
use App\Models\Title;
use App\Services\Plex\PlexException;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();
});

function libraryIndexFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/Fixtures/plex/{$name}.json")),
        true,
    );
}

test('it indexes every movie/show section, skipping items with no usable guid', function () {
    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(libraryIndexFixture('library_sections')),
        '*plex.local:32400/library/sections/1/all*' => Http::response(libraryIndexFixture('library_section_movies')),
        '*plex.local:32400/library/sections/2/all*' => Http::response(libraryIndexFixture('library_section_shows')),
        '*plex.local:32400/library/sections/4/all*' => Http::response(['MediaContainer' => ['size' => 0]]),
        '*plex.local:32400/library/sections/5/all*' => Http::response(['MediaContainer' => ['size' => 0]]),
        '*plex.local:32400/library/metadata/54321/allLeaves*' => Http::response(libraryIndexFixture('all_leaves_show')),
    ]);

    $result = app(SyncPlexLibraryIndex::class)->handle();

    expect($result['sections'])->toBe(4)
        ->and($result['items_indexed'])->toBe(2)
        ->and($result['episodes_indexed'])->toBe(3)
        ->and(PlexLibraryItem::count())->toBe(2);

    $movie = PlexLibraryItem::query()->where('tmdb_id', 937249)->first();

    expect($movie)->not->toBeNull()
        ->and($movie->plex_rating_key)->toBe('12345')
        ->and($movie->type)->toBe(TitleType::Movie)
        ->and($movie->imdb_id)->toBe('tt13157592')
        ->and($movie->machine_identifier)->toBe('abc123def456');

    // The local:// item (no Guid[]) was skipped.
    expect(PlexLibraryItem::query()->where('plex_rating_key', '99999')->exists())->toBeFalse();

    $show = PlexLibraryItem::query()->where('tmdb_id', 157744)->first();

    expect($show)->not->toBeNull()
        ->and($show->plex_rating_key)->toBe('54321')
        ->and($show->type)->toBe(TitleType::Show)
        ->and($show->tvdb_id)->toBe(422948);

    // allLeaves is fetched exactly once for the one show, not once per episode.
    expect(Http::recorded(fn ($request) => str_contains((string) $request->url(), 'allLeaves')))->toHaveCount(1);
});

test('it stores a show’s episodes from a single allLeaves call', function () {
    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => [
            ['key' => '2', 'type' => 'show', 'title' => 'TV Shows'],
        ]]]),
        '*plex.local:32400/library/sections/2/all*' => Http::response(libraryIndexFixture('library_section_shows')),
        '*plex.local:32400/library/metadata/54321/allLeaves*' => Http::response(libraryIndexFixture('all_leaves_show')),
    ]);

    app(SyncPlexLibraryIndex::class)->handle();

    expect(PlexLibraryEpisode::count())->toBe(3);

    $episode = PlexLibraryEpisode::query()
        ->where('show_rating_key', '54321')
        ->where('season_number', 1)
        ->where('episode_number', 1)
        ->first();

    expect($episode)->not->toBeNull()
        ->and($episode->plex_rating_key)->toBe('901')
        ->and($episode->machine_identifier)->toBe('abc123def456');

    Http::assertSentCount(4);
});

test('it pages through a section until a page comes back short', function () {
    $page1Items = [];

    for ($i = 1; $i <= 200; $i++) {
        $page1Items[] = [
            'ratingKey' => (string) (1000 + $i),
            'type' => 'movie',
            'title' => "Filler {$i}",
            'Guid' => [['id' => 'tmdb://'.(500000 + $i)]],
        ];
    }

    $page1 = ['MediaContainer' => ['size' => 200, 'totalSize' => 201, 'Metadata' => $page1Items]];
    $page2 = libraryIndexFixture('library_section_movies');
    $page2['MediaContainer']['Metadata'] = [$page2['MediaContainer']['Metadata'][0]];

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => [
            ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ]]]),
        '*plex.local:32400/library/sections/1/all*' => Http::sequence()
            ->push($page1)
            ->push($page2),
    ]);

    $result = app(SyncPlexLibraryIndex::class)->handle();

    expect($result['items_indexed'])->toBe(201)
        ->and(PlexLibraryItem::count())->toBe(201);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Container-Start', '0'));
    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Plex-Container-Start', '200'));
});

test('it removes items no longer on the server at the end of a full sync', function () {
    PlexLibraryItem::factory()->create([
        'plex_rating_key' => 'gone',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 999999,
        'indexed_at' => now()->subWeek(),
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => [
            ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ]]]),
        '*plex.local:32400/library/sections/1/all*' => Http::response(libraryIndexFixture('library_section_movies')),
    ]);

    $result = app(SyncPlexLibraryIndex::class)->handle();

    expect($result['removed'])->toBe(1)
        ->and(PlexLibraryItem::query()->where('plex_rating_key', 'gone')->exists())->toBeFalse()
        ->and(PlexLibraryItem::query()->where('plex_rating_key', '12345')->exists())->toBeTrue();
});

test('it skips stale-row cleanup when no library sections are found', function () {
    PlexLibraryItem::factory()->create([
        'plex_rating_key' => 'existing',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 111111,
        'indexed_at' => now()->subWeek(),
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => []]]),
    ]);

    $result = app(SyncPlexLibraryIndex::class)->handle();

    expect($result['cleanup_skipped'])->toBeTrue()
        ->and($result['cleanup_skip_reason'])->toBe('no library sections were found')
        ->and($result['removed'])->toBe(0)
        ->and(PlexLibraryItem::query()->where('plex_rating_key', 'existing')->exists())->toBeTrue();
});

test('it skips stale-row cleanup when no items are indexed', function () {
    PlexLibraryItem::factory()->create([
        'plex_rating_key' => 'existing',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 111111,
        'indexed_at' => now()->subWeek(),
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => [
            ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ]]]),
        '*plex.local:32400/library/sections/1/all*' => Http::response(['MediaContainer' => ['size' => 0]]),
    ]);

    $result = app(SyncPlexLibraryIndex::class)->handle();

    expect($result['cleanup_skipped'])->toBeTrue()
        ->and($result['cleanup_skip_reason'])->toBe('no items were indexed')
        ->and($result['removed'])->toBe(0)
        ->and(PlexLibraryItem::query()->where('plex_rating_key', 'existing')->exists())->toBeTrue();
});

test('it skips stale-row cleanup when the machine identifier is unreachable', function () {
    PlexLibraryItem::factory()->create([
        'plex_rating_key' => 'existing',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 111111,
        'indexed_at' => now()->subWeek(),
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(['MediaContainer' => []]),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => [
            ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ]]]),
        '*plex.local:32400/library/sections/1/all*' => Http::response(libraryIndexFixture('library_section_movies')),
    ]);

    $result = app(SyncPlexLibraryIndex::class)->handle();

    expect($result['cleanup_skipped'])->toBeTrue()
        ->and($result['cleanup_skip_reason'])->toBe('the Plex machine identifier was unreachable')
        ->and($result['removed'])->toBe(0)
        ->and(PlexLibraryItem::query()->where('plex_rating_key', 'existing')->exists())->toBeTrue();
});

test('it skips stale-row cleanup when this run indexed dramatically fewer items than last time', function () {
    app(IntegrationSettings::class)->set('plex.library_index', [
        'status' => 'success',
        'items_indexed' => 100,
        'finished_at' => now()->subDay()->toIso8601String(),
    ]);

    PlexLibraryItem::factory()->create([
        'plex_rating_key' => 'existing',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 111111,
        'indexed_at' => now()->subWeek(),
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => [
            ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ]]]),
        '*plex.local:32400/library/sections/1/all*' => Http::response(libraryIndexFixture('library_section_movies')),
    ]);

    $result = app(SyncPlexLibraryIndex::class)->handle();

    expect($result['items_indexed'])->toBe(1)
        ->and($result['cleanup_skipped'])->toBeTrue()
        ->and($result['cleanup_skip_reason'])->toContain('dropped more than 50%')
        ->and($result['removed'])->toBe(0)
        ->and(PlexLibraryItem::query()->where('plex_rating_key', 'existing')->exists())->toBeTrue();
});

test('--force overrides the guard and removes stale rows even on a bad run', function () {
    PlexLibraryItem::factory()->create([
        'plex_rating_key' => 'existing',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 111111,
        'indexed_at' => now()->subWeek(),
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => []]]),
    ]);

    $result = app(SyncPlexLibraryIndex::class)->handle(force: true);

    expect($result['cleanup_skipped'])->toBeFalse()
        ->and($result['removed'])->toBe(1)
        ->and(PlexLibraryItem::query()->where('plex_rating_key', 'existing')->exists())->toBeFalse();
});

test('a mid-run exception propagates without deleting anything', function () {
    PlexLibraryItem::factory()->create([
        'plex_rating_key' => 'existing',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 111111,
        'indexed_at' => now()->subWeek(),
    ]);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(null, 500),
    ]);

    expect(fn () => app(SyncPlexLibraryIndex::class)->handle())->toThrow(PlexException::class);

    expect(PlexLibraryItem::query()->where('plex_rating_key', 'existing')->exists())->toBeTrue();
});

test('it invokes the progress callback once per item encountered, including skipped ones', function () {
    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => [
            ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ]]]),
        '*plex.local:32400/library/sections/1/all*' => Http::response(libraryIndexFixture('library_section_movies')),
    ]);

    $events = [];

    app(SyncPlexLibraryIndex::class)->handle(onProgress: function (array $event) use (&$events): void {
        $events[] = $event;
    });

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBe(['section' => 'Movies', 'title' => '57 Seconds'])
        ->and($events[1])->toBe(['section' => 'Movies', 'title' => 'Home Video']);
});

test('it refreshes every Title’s cached plex_items row from the freshly synced index', function () {
    $title = Title::factory()->movie()->create(['tmdb_id' => 937249, 'imdb_id' => 'tt13157592']);

    Http::fake([
        '*plex.local:32400/identity*' => Http::response(libraryIndexFixture('identity')),
        '*plex.local:32400/library/sections' => Http::response(['MediaContainer' => ['Directory' => [
            ['key' => '1', 'type' => 'movie', 'title' => 'Movies'],
        ]]]),
        '*plex.local:32400/library/sections/1/all*' => Http::response(libraryIndexFixture('library_section_movies')),
    ]);

    $result = app(SyncPlexLibraryIndex::class)->handle();

    expect($result['titles_refreshed'])->toBe(1)
        ->and($title->plexItem()->first()->rating_key)->toBe('12345');
});
