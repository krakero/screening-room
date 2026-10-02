<?php

use App\Enums\PlaySource;
use App\Jobs\RefreshSeasonTrailer;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('season detail requires authentication', function () {
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);

    $this->getJson("/api/v1/titles/{$title->id}/seasons/1")->assertUnauthorized();
});

test('an unknown season number returns 404', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');
    $title = Title::factory()->show()->create();

    $this->getJson("/api/v1/titles/{$title->id}/seasons/99")->assertNotFound();
});

test('season detail matches the contract shape and loads never-synced episodes', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(), 'sanctum');

    config(['services.tmdb.token' => 'test-token']);
    config(['services.tmdb.base_url' => 'https://api.themoviedb.org/3']);

    $title = Title::factory()->show()->create(['tmdb_id' => 1396]);
    $season = Season::factory()->notLoaded()->for($title)->create([
        'season_number' => 1,
        'tmdb_id' => 3572,
        'trailer_checked_at' => now(),
    ]);

    Http::fake([
        '*/tv/1396*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/tmdb/show_1396_seasons.json')), true)),
    ]);

    $response = $this->getJson("/api/v1/titles/{$title->id}/seasons/1");

    $response->assertOk()->assertJson([
        'id' => $season->id,
        'title_id' => $title->id,
        'season_number' => 1,
        'awaiting_trailer' => false,
    ]);

    expect($response->json('episodes'))->not->toBeEmpty();
    expect($response->json('episodes.0'))->toHaveKeys([
        'id', 'season_number', 'episode_number', 'name', 'air_date', 'still_url',
        'runtime', 'is_special', 'has_aired', 'watched', 'has_manual_play', 'is_up_next',
        'plex_available', 'plex_url',
    ]);
});

test('episodes report watched state, up-next and previous/next season numbers', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create();
    $season1 = Season::factory()->for($title)->create(['season_number' => 1]);
    $season2 = Season::factory()->for($title)->create(['season_number' => 2]);

    $episodes = Episode::factory()->aired()->for($season1)->count(2)->sequence(
        ['episode_number' => 1],
        ['episode_number' => 2],
    )->create(['title_id' => $title->id, 'season_number' => 1]);

    Play::factory()->for($episodes->first(), 'playable')->create(['source' => PlaySource::Manual]);

    $response = $this->getJson("/api/v1/titles/{$title->id}/seasons/1");

    $response->assertOk()
        ->assertJson([
            'previous_season_number' => null,
            'next_season_number' => 2,
            'aired_unwatched_count' => 1,
            'next_episode_id' => $episodes->last()->id,
        ]);

    $response->assertJsonPath('episodes.0.watched', true)
        ->assertJsonPath('episodes.0.has_manual_play', true)
        ->assertJsonPath('episodes.1.watched', false)
        ->assertJsonPath('episodes.1.is_up_next', true);
});

test('it dispatches a trailer refresh when the season has never been checked', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create([
        'season_number' => 1,
        'trailer_key' => null,
        'trailer_checked_at' => null,
    ]);

    $response = $this->getJson("/api/v1/titles/{$title->id}/seasons/1");

    $response->assertOk()->assertJson(['awaiting_trailer' => true]);

    Queue::assertPushed(RefreshSeasonTrailer::class, fn (RefreshSeasonTrailer $job) => $job->season->is($season));
});

test('season episode listing does not N+1 per episode', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->count(10)->sequence(fn ($sequence) => ['episode_number' => $sequence->index + 1])
        ->create(['title_id' => $title->id, 'season_number' => 1]);

    DB::enableQueryLog();

    $this->getJson("/api/v1/titles/{$title->id}/seasons/1")->assertOk();

    $queryCount = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(15);
});
