<?php

use App\Enums\FollowState;
use App\Enums\PlaySource;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('the rewatch endpoints require authentication', function () {
    $title = Title::factory()->show()->create();

    $this->postJson("/api/v1/titles/{$title->id}/restart")->assertUnauthorized();
    $this->postJson("/api/v1/titles/{$title->id}/stop-rewatch")->assertUnauthorized();
});

test('restarting an unfollowed show creates a watching follow with a fresh rewatch start', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/restart");

    $response->assertOk()->assertJson(['follow' => [
        'state' => 'watching',
        'rewatching' => true,
        'rewatch_count' => 0,
    ]]);
    expect(Follow::query()->where('title_id', $title->id)->first())
        ->rewatch_started_at->not->toBeNull();
});

test('restarting a completed show sets it watching and rewatching again', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->completed()->create(['rewatch_count' => 1]);

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/restart");

    $response->assertOk()->assertJson(['follow' => [
        'id' => $follow->id,
        'state' => 'watching',
        'rewatching' => true,
        'rewatch_count' => 1,
    ]]);
});

test('restarting a paused or abandoned show resumes it into a rewatch', function () {
    $user = User::factory()->create();

    $pausedTitle = Title::factory()->show()->create();
    Follow::factory()->for($pausedTitle)->paused()->create();

    $abandonedTitle = Title::factory()->show()->create();
    Follow::factory()->for($abandonedTitle)->abandoned()->create();

    $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$pausedTitle->id}/restart")
        ->assertOk()->assertJson(['follow' => ['state' => 'watching', 'rewatching' => true]]);

    $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$abandonedTitle->id}/restart")
        ->assertOk()->assertJson(['follow' => ['state' => 'watching', 'rewatching' => true]]);
});

test('restarting an unknown title returns a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/titles/999999/restart')->assertNotFound();
});

test('stopping a rewatch with nothing else watched returns to watching', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->for($season)->aired()->create(['season_number' => 1, 'episode_number' => 1]);
    $follow = Follow::factory()->for($title)->rewatching()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/stop-rewatch");

    $response->assertOk()->assertJson(['follow' => [
        'id' => $follow->id,
        'state' => 'watching',
        'rewatching' => false,
    ]]);
    expect($follow->refresh()->rewatch_started_at)->toBeNull();
});

test('stopping a rewatch completes the follow when everything has ever been watched', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create(['in_production' => false]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['season_number' => 1, 'episode_number' => 1]);
    $follow = Follow::factory()->for($title)->rewatching()->create(['rewatch_count' => 0]);
    Play::factory()->for($episode, 'playable')->create(['watched_at' => now()->subDays(30)]);

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/stop-rewatch");

    $response->assertOk()->assertJson(['follow' => [
        'id' => $follow->id,
        'state' => 'completed',
        'rewatching' => false,
    ]]);
});

test('stopping a rewatch that is not rewatching is a no-op', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->create(['state' => FollowState::Watching]);

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/stop-rewatch");

    $response->assertOk()->assertJson(['follow' => ['id' => $follow->id, 'state' => 'watching']]);
});

test('stopping a rewatch on a title with no follow returns a null follow', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/stop-rewatch");

    $response->assertOk()->assertJson(['follow' => null]);
});

test('finishing a rewatch via a play auto-completes it and increments the count', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create(['in_production' => false]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['season_number' => 1, 'episode_number' => 1]);
    $follow = Follow::factory()->for($title)->rewatching()->create(['rewatch_count' => 2]);

    $this->actingAs($user, 'sanctum')->postJson("/api/v1/episodes/{$episode->id}/watch")->assertCreated();

    $follow->refresh();
    expect($follow->state)->toBe(FollowState::Completed)
        ->and($follow->rewatch_count)->toBe(3)
        ->and($follow->rewatch_started_at)->toBeNull();
});

test('title payload exposes rewatching and rewatch_count on the follow', function () {
    Queue::fake();
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    Follow::factory()->for($title)->rewatching()->create(['rewatch_count' => 4]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/titles/{$title->id}");

    $response->assertOk()->assertJson(['follow' => ['rewatching' => true, 'rewatch_count' => 4]]);
});

test('season episodes report watched only since the rewatch restart', function () {
    Queue::fake();
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->aired()->create(['season_number' => 1, 'episode_number' => 1]);
    Follow::factory()->for($title)->rewatching()->create();
    Play::factory()->for($episode, 'playable')->create(['watched_at' => now()->subDays(30)]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/titles/{$title->id}/seasons/1");

    $response->assertOk();
    $episodePayload = collect($response->json('episodes'))->firstWhere('id', $episode->id);
    expect($episodePayload['watched'])->toBeFalse();

    $episode->plays()->create(['watched_at' => now(), 'source' => PlaySource::Manual]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/titles/{$title->id}/seasons/1");
    $episodePayload = collect($response->json('episodes'))->firstWhere('id', $episode->id);
    expect($episodePayload['watched'])->toBeTrue();
});

test('episode season stats count watched only since the rewatch restart', function () {
    Queue::fake();
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'episode_count' => 2]);
    $episodeOne = Episode::factory()->for($season)->aired()->create(['season_number' => 1, 'episode_number' => 1]);
    $episodeTwo = Episode::factory()->for($season)->aired()->create(['season_number' => 1, 'episode_number' => 2]);
    Follow::factory()->for($title)->rewatching()->create();
    Play::factory()->for($episodeOne, 'playable')->create(['watched_at' => now()->subDays(30)]);
    Play::factory()->for($episodeTwo, 'playable')->create(['watched_at' => now()]);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/episodes/{$episodeOne->id}");

    $response->assertOk()->assertJson(['season_stats' => ['total' => 2, 'watched' => 1]]);
});

test('up next continue watching starts a restarted show back at S1E1', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episodeOne = Episode::factory()->for($season)->aired()->create(['season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->for($season)->aired()->create(['season_number' => 1, 'episode_number' => 2]);
    Follow::factory()->for($title)->rewatching()->create();
    Play::factory()->for($episodeOne, 'playable')->create(['watched_at' => now()->subDays(30)]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/up-next');

    $response->assertOk();
    expect($response->json('continue_watching.0.next_episode.id'))->toBe($episodeOne->id);
});
