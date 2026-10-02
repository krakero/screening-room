<?php

use App\Enums\CreditType;
use App\Enums\FollowState;
use App\Enums\RatingSource;
use App\Jobs\RefreshSeasonTrailer;
use App\Jobs\RefreshTitleRatings;
use App\Jobs\RefreshTitleTrailer;
use App\Models\Credit;
use App\Models\Episode;
use App\Models\ExternalRating;
use App\Models\Follow;
use App\Models\MediaList;
use App\Models\Person;
use App\Models\Play;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('title detail requires authentication', function () {
    $title = Title::factory()->movie()->create();

    $this->getJson("/api/v1/titles/{$title->id}")->assertUnauthorized();
});

test('a missing title returns 404', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $this->getJson('/api/v1/titles/999999')->assertNotFound();
});

test('movie detail matches the contract shape', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->movie()->create([
        'trailer_site' => 'YouTube',
        'trailer_key' => 'abc123',
        'trailer_checked_at' => now(),
        'ratings_checked_at' => now(),
    ]);

    ExternalRating::factory()->for($title)->create([
        'source' => RatingSource::Imdb,
        'value' => 9.3,
        'max' => 10,
        'votes' => 2000000,
        'url' => 'https://imdb.com/title/tt123',
    ]);

    $play = Play::factory()->for($title, 'playable')->create();

    $person = Person::factory()->create();
    Credit::factory()->for($person)->create([
        'creditable_type' => (new Title)->getMorphClass(),
        'creditable_id' => $title->id,
        'type' => CreditType::Cast,
    ]);

    $response = $this->getJson("/api/v1/titles/{$title->id}");

    $response->assertOk()->assertJson([
        'id' => $title->id,
        'type' => 'movie',
        'tmdb_id' => $title->tmdb_id,
        'name' => $title->name,
        'poster_url' => $title->posterUrl(),
        'poster_url_w185' => $title->posterUrl('w185'),
        'backdrop_url' => $title->backdropUrl(),
        'backdrop_url_w780' => $title->backdropUrl('w780'),
        'trailer' => [
            'site' => 'YouTube',
            'key' => 'abc123',
        ],
        'awaiting_trailer' => false,
        'follow' => null,
        'on_watchlist' => false,
        'rating' => null,
        'plex_play_url' => null,
        'external_ratings' => [
            [
                'source' => 'imdb',
                'value' => 9.3,
                'max' => 10.0,
                'votes' => 2000000,
                'formatted' => '9.3',
            ],
        ],
        'movie_plays' => [
            ['id' => $play->id, 'source' => 'manual'],
        ],
        'cast' => [
            ['id' => $person->credits->first()->id, 'character' => $person->credits->first()->character],
        ],
    ])
        ->assertJsonMissingPath('seasons')
        ->assertJsonMissingPath('progress')
        ->assertJsonMissingPath('show_status');

    Queue::assertNotPushed(RefreshTitleTrailer::class);
    Queue::assertNotPushed(RefreshTitleRatings::class);
});

test('show detail includes seasons, progress and follow state', function () {
    Queue::fake();
    $user = $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create([
        'status' => 'Ended',
        'trailer_checked_at' => now(),
    ]);
    $follow = Follow::factory()->for($title)->create(['state' => FollowState::Watching]);

    $season = Season::factory()->for($title)->create(['season_number' => 1, 'episode_count' => 2]);
    $episodes = Episode::factory()->aired()->for($season)->count(2)->sequence(
        ['episode_number' => 1],
        ['episode_number' => 2],
    )->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($episodes->first(), 'playable')->create();

    $rating = Rating::factory()->create([
        'rateable_type' => (new Title)->getMorphClass(),
        'rateable_id' => $title->id,
        'score' => 9,
    ]);

    $watchlist = MediaList::watchlist();
    $watchlist->titles()->attach($title, ['position' => 0]);

    $response = $this->getJson("/api/v1/titles/{$title->id}");

    $response->assertOk()->assertJson([
        'type' => 'show',
        'show_status' => 'ended',
        'follow' => ['id' => $follow->id, 'state' => 'watching'],
        'on_watchlist' => true,
        'rating' => ['score' => 9],
        'progress' => [
            'aired_count' => 2,
            'watched_count' => 1,
        ],
    ]);

    $response->assertJsonPath('seasons.0.id', $season->id)
        ->assertJsonPath('seasons.0.percent_watched', 50)
        ->assertJsonPath('seasons.0.complete', false)
        ->assertJsonMissingPath('movie_plays');
});

test('it dispatches trailer and rating refreshes when stale and reports awaiting_trailer', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->movie()->create([
        'trailer_key' => null,
        'trailer_checked_at' => null,
        'ratings_checked_at' => null,
    ]);

    $response = $this->getJson("/api/v1/titles/{$title->id}");

    $response->assertOk()->assertJson(['awaiting_trailer' => true]);

    Queue::assertPushed(RefreshTitleTrailer::class, fn (RefreshTitleTrailer $job) => $job->title->is($title));
    Queue::assertPushed(RefreshTitleRatings::class, fn (RefreshTitleRatings $job) => $job->title->is($title));
});

test('it does not dispatch refreshes when everything is fresh', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->movie()->create([
        'trailer_site' => 'YouTube',
        'trailer_key' => 'abc123',
        'trailer_checked_at' => now(),
        'ratings_checked_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/titles/{$title->id}");

    $response->assertOk()->assertJson(['awaiting_trailer' => false]);

    Queue::assertNothingPushed();
});

test('it dispatches a season trailer refresh for the current season of a show', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create(['trailer_checked_at' => now()]);
    $season = Season::factory()->for($title)->create([
        'season_number' => 1,
        'air_date' => now()->subDays(5),
        'trailer_key' => null,
        'trailer_checked_at' => null,
    ]);

    $response = $this->getJson("/api/v1/titles/{$title->id}");

    $response->assertOk()->assertJson(['awaiting_season_trailer' => true]);

    Queue::assertPushed(RefreshSeasonTrailer::class, fn (RefreshSeasonTrailer $job) => $job->season->is($season));
});
