<?php

use App\Actions\Plex\RecordScrobble;
use App\Enums\PlaySource;
use App\Enums\TitleType;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Support\IntegrationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    app(IntegrationSettings::class)->setMany([
        'plex.account_id' => '1',
    ]);

    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    Http::preventStrayRequests();
});

function movieTmdbFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/movie_603.json')), true);
}

test('it records a play for an existing movie', function () {
    $title = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    $event = [
        'account_id' => '1',
        'rating_key' => '501',
        'viewed_at' => Carbon::createFromTimestamp(1758000000),
        'type' => 'movie',
        'title' => 'The Matrix',
        'grandparent_title' => null,
        'season_number' => null,
        'episode_number' => null,
        'guids' => ['tmdb' => '603'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->not->toBeNull()
        ->and($play->source)->toBe(PlaySource::Plex)
        ->and($play->external_id)->toBe('501:1758000000')
        ->and($play->playable_id)->toBe($title->id);
});

test('it imports a missing movie from tmdb before recording the play', function () {
    Http::fake([
        '*/movie/603*' => Http::response(movieTmdbFixture()),
    ]);

    $event = [
        'account_id' => '1',
        'rating_key' => '501',
        'viewed_at' => Carbon::createFromTimestamp(1758000000),
        'type' => 'movie',
        'title' => 'The Matrix',
        'grandparent_title' => null,
        'season_number' => null,
        'episode_number' => null,
        'guids' => ['tmdb' => '603'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect(Title::where('tmdb_id', 603)->exists())->toBeTrue()
        ->and($play)->not->toBeNull();
});

test('it records a play against a local episode matched by its own tmdb id', function () {
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'tmdb_id' => 62085,
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    $event = [
        'account_id' => '1',
        'rating_key' => '901',
        'viewed_at' => Carbon::createFromTimestamp(1758000500),
        'type' => 'episode',
        'title' => 'Winter Is Coming',
        'grandparent_title' => 'Game of Thrones',
        'season_number' => 1,
        'episode_number' => 1,
        'guids' => ['tmdb' => '62085'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->not->toBeNull()
        ->and($play->playable_id)->toBe($episode->id)
        ->and($play->playable_type)->toBe('episode');
});

test('it records a play against a local episode matched by its own tvdb id', function () {
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'tvdb_id' => 3254641,
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    $event = [
        'account_id' => '1',
        'rating_key' => '901',
        'viewed_at' => Carbon::createFromTimestamp(1758000500),
        'type' => 'episode',
        'title' => 'Winter Is Coming',
        'grandparent_title' => 'Game of Thrones',
        'season_number' => 1,
        'episode_number' => 1,
        'guids' => ['tvdb' => '3254641'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->not->toBeNull()
        ->and($play->playable_id)->toBe($episode->id);
});

function findTvdbEpisodeFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/find_tvdb_episode_3254641.json')), true);
}

function findImdbEpisodeFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/find_imdb_episode_tt1480055.json')), true);
}

function showTmdbFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/show_1396.json')), true);
}

function showTmdbSeasonsFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/show_1396_seasons.json')), true);
}

test('it resolves an unmatched episode via TMDB find on its own tvdb id, importing the show', function () {
    Bus::fake();

    Http::fake([
        '*/find/3254641*' => Http::response(findTvdbEpisodeFixture()),
        '*/tv/1396*' => Http::sequence()
            ->push(showTmdbFixture())
            ->push(showTmdbSeasonsFixture()),
    ]);

    $event = [
        'account_id' => '1',
        'rating_key' => '901',
        'viewed_at' => Carbon::createFromTimestamp(1758000500),
        'type' => 'episode',
        'title' => 'Pilot',
        'grandparent_title' => 'Breaking Bad',
        'season_number' => 1,
        'episode_number' => 1,
        'guids' => ['tvdb' => '3254641'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect(Title::where('tmdb_id', 1396)->exists())->toBeTrue()
        ->and($play)->not->toBeNull()
        ->and(Episode::where('tmdb_id', 62085)->first()->id)->toBe($play->playable_id);
});

test('it resolves an unmatched episode via TMDB find on its own imdb id when no tvdb id is present', function () {
    Bus::fake();

    Http::fake([
        '*/find/tt1480055*' => Http::response(findImdbEpisodeFixture()),
        '*/tv/1396*' => Http::sequence()
            ->push(showTmdbFixture())
            ->push(showTmdbSeasonsFixture()),
    ]);

    $event = [
        'account_id' => '1',
        'rating_key' => '901',
        'viewed_at' => Carbon::createFromTimestamp(1758000500),
        'type' => 'episode',
        'title' => 'Pilot',
        'grandparent_title' => 'Breaking Bad',
        'season_number' => 1,
        'episode_number' => 1,
        'guids' => ['imdb' => 'tt1480055'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->not->toBeNull();
});

test('it resolves an episode via show_guids (e.g. from the legacy tvdb agent or a token-backed lookup) without calling TMDB find', function () {
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396, 'tvdb_id' => 81189]);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    $event = [
        'account_id' => '1',
        'rating_key' => '902',
        'viewed_at' => Carbon::createFromTimestamp(1758000600),
        'type' => 'episode',
        'title' => 'Pilot',
        'grandparent_title' => 'Breaking Bad',
        'season_number' => 1,
        'episode_number' => 1,
        'guids' => [],
        'show_guids' => ['tvdb' => '81189'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->not->toBeNull()
        ->and($play->playable_id)->toBe($episode->id);

    Http::assertNothingSent();
});

test('it ensures an unloaded season\'s episodes are imported before matching, instead of leaving the play unmatched', function () {
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396, 'tvdb_id' => 81189]);
    $season = Season::factory()->notLoaded()->for($show, 'title')->create(['season_number' => 1]);

    expect(Episode::where('season_id', $season->id)->count())->toBe(0);

    Http::fake([
        '*/tv/1396*' => Http::response([
            'id' => 1396,
            'season/1' => [
                'id' => 3572,
                'season_number' => 1,
                'episodes' => [
                    ['id' => 62085, 'episode_number' => 1, 'season_number' => 1, 'name' => 'Pilot', 'air_date' => '2008-01-20'],
                ],
            ],
        ]),
    ]);

    $event = [
        'account_id' => '1',
        'rating_key' => '902',
        'viewed_at' => Carbon::createFromTimestamp(1758000600),
        'type' => 'episode',
        'title' => 'Pilot',
        'grandparent_title' => 'Breaking Bad',
        'season_number' => 1,
        'episode_number' => 1,
        'guids' => [],
        'show_guids' => ['tvdb' => '81189'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->not->toBeNull()
        ->and(Episode::where('season_id', $season->id)->where('tmdb_id', 62085)->exists())->toBeTrue()
        ->and($play->playable_id)->toBe(Episode::where('tmdb_id', 62085)->firstOrFail()->id)
        ->and($season->fresh()->episodesLoaded())->toBeTrue();
});

test('it imports the show from show_guids via TMDB find when not stored locally', function () {
    Bus::fake();

    Http::fake([
        '*/find/81189*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/find_tvdb_show_81189.json')), true)),
        '*/tv/1396*' => Http::sequence()
            ->push(showTmdbFixture())
            ->push(showTmdbSeasonsFixture()),
    ]);

    $event = [
        'account_id' => '1',
        'rating_key' => '902',
        'viewed_at' => Carbon::createFromTimestamp(1758000600),
        'type' => 'episode',
        'title' => 'Pilot',
        'grandparent_title' => 'Breaking Bad',
        'season_number' => 1,
        'episode_number' => 1,
        'guids' => [],
        'show_guids' => ['tvdb' => '81189'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect(Title::where('tmdb_id', 1396)->exists())->toBeTrue()
        ->and($play)->not->toBeNull();
});

test('it falls back to matching a local show by grandparent title when no guids resolve it', function () {
    $show = Title::factory()->create(['type' => TitleType::Show, 'tmdb_id' => 1396, 'name' => 'Breaking Bad']);
    $season = Season::factory()->for($show, 'title')->create(['season_number' => 1]);
    $episode = Episode::factory()->for($show, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
    ]);

    $event = [
        'account_id' => '1',
        'rating_key' => '903',
        'viewed_at' => Carbon::createFromTimestamp(1758000700),
        'type' => 'episode',
        'title' => 'Pilot',
        'grandparent_title' => 'Breaking Bad',
        'season_number' => 1,
        'episode_number' => 1,
        'guids' => [],
        'show_guids' => [],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->not->toBeNull()
        ->and($play->playable_id)->toBe($episode->id);

    Http::assertNothingSent();
});

test('it does not match an episode when no guids or grandparent title resolve a show', function () {
    $event = [
        'account_id' => '1',
        'rating_key' => '904',
        'viewed_at' => Carbon::createFromTimestamp(1758000800),
        'type' => 'episode',
        'title' => 'Pilot',
        'grandparent_title' => null,
        'season_number' => 1,
        'episode_number' => 1,
        'guids' => [],
        'show_guids' => [],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->toBeNull()
        ->and(Play::count())->toBe(0);
});

test('it imports a missing movie from tmdb via its imdb id when no tmdb guid is present', function () {
    Http::fake([
        '*/find/tt0133093*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/find_imdb_movie_tt0133093.json')), true)),
        '*/movie/603*' => Http::response(movieTmdbFixture()),
    ]);

    $event = [
        'account_id' => '1',
        'rating_key' => '501',
        'viewed_at' => Carbon::createFromTimestamp(1758000000),
        'type' => 'movie',
        'title' => 'The Matrix',
        'grandparent_title' => null,
        'season_number' => null,
        'episode_number' => null,
        'guids' => ['imdb' => 'tt0133093'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect(Title::where('tmdb_id', 603)->exists())->toBeTrue()
        ->and($play)->not->toBeNull();
});

test('it ignores plays from an account other than the configured one', function () {
    Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    $event = [
        'account_id' => '2',
        'rating_key' => '501',
        'viewed_at' => Carbon::createFromTimestamp(1758000000),
        'type' => 'movie',
        'title' => 'The Matrix',
        'grandparent_title' => null,
        'season_number' => null,
        'episode_number' => null,
        'guids' => ['tmdb' => '603'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->toBeNull()
        ->and(Play::count())->toBe(0);
});

test('it records plays from any account when no account id is configured', function () {
    app(IntegrationSettings::class)->forget('plex.account_id');

    Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    $event = [
        'account_id' => '7',
        'rating_key' => '501',
        'viewed_at' => Carbon::createFromTimestamp(1758000000),
        'type' => 'movie',
        'title' => 'The Matrix',
        'grandparent_title' => null,
        'season_number' => null,
        'episode_number' => null,
        'guids' => ['tmdb' => '603'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->not->toBeNull()
        ->and(Play::count())->toBe(1);
});

test('it ignores a play with no account id when an account id is configured', function () {
    Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    $event = [
        'account_id' => null,
        'rating_key' => '501',
        'viewed_at' => Carbon::createFromTimestamp(1758000000),
        'type' => 'movie',
        'title' => 'The Matrix',
        'grandparent_title' => null,
        'season_number' => null,
        'episode_number' => null,
        'guids' => ['tmdb' => '603'],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->toBeNull()
        ->and(Play::count())->toBe(0);
});

test('it dedupes plays with the same rating key and viewed at', function () {
    $title = Title::factory()->create(['type' => TitleType::Movie, 'tmdb_id' => 603]);

    $event = [
        'account_id' => '1',
        'rating_key' => '501',
        'viewed_at' => Carbon::createFromTimestamp(1758000000),
        'type' => 'movie',
        'title' => 'The Matrix',
        'grandparent_title' => null,
        'season_number' => null,
        'episode_number' => null,
        'guids' => ['tmdb' => '603'],
    ];

    $first = app(RecordScrobble::class)->handle($event);
    $second = app(RecordScrobble::class)->handle($event);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($title->plays()->count())->toBe(1);
});

test('it ignores events with no usable guids', function () {
    $event = [
        'account_id' => '1',
        'rating_key' => '501',
        'viewed_at' => Carbon::createFromTimestamp(1758000000),
        'type' => 'movie',
        'title' => 'Unmatchable',
        'grandparent_title' => null,
        'season_number' => null,
        'episode_number' => null,
        'guids' => [],
    ];

    $play = app(RecordScrobble::class)->handle($event);

    expect($play)->toBeNull()
        ->and(Play::count())->toBe(0);
});
