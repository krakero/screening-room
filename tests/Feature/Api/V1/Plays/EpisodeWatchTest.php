<?php

use App\Actions\Plays\RemovePlay;
use App\Enums\PlaySource;
use App\Models\Episode;
use App\Models\Play;
use App\Models\User;

test('watching an episode requires authentication', function () {
    $episode = Episode::factory()->create();

    $response = $this->postJson("/api/v1/episodes/{$episode->id}/watch");

    $response->assertUnauthorized();
});

test('watching an episode logs a play', function () {
    $user = User::factory()->create();
    $episode = Episode::factory()->aired()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/episodes/{$episode->id}/watch");

    $response->assertCreated()->assertJsonStructure(['play' => ['id', 'watched_at', 'source']])
        ->assertJson(['play' => ['source' => 'manual']]);

    expect($episode->plays()->count())->toBe(1);
});

test('watching an episode accepts release_date, unknown and custom when values', function () {
    $user = User::factory()->create();
    $episode = Episode::factory()->aired()->create(['air_date' => '2020-01-01']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/episodes/{$episode->id}/watch", ['when' => 'release_date'])
        ->assertCreated();

    expect($episode->plays()->sole()->watched_at->toDateString())->toBe('2020-01-01');

    app(RemovePlay::class)->handle($episode->plays()->sole());

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/episodes/{$episode->id}/watch", ['when' => 'unknown'])
        ->assertCreated();

    expect($episode->plays()->sole()->watched_at)->toBeNull();

    app(RemovePlay::class)->handle($episode->plays()->sole());

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/episodes/{$episode->id}/watch", [
            'when' => 'custom',
            'datetime' => '2021-06-15T12:00:00Z',
        ])
        ->assertCreated();

    expect($episode->plays()->sole()->watched_at->toIso8601ZuluString())->toBe('2021-06-15T12:00:00Z');
});

test('watching an episode with when custom requires a datetime', function () {
    $user = User::factory()->create();
    $episode = Episode::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/episodes/{$episode->id}/watch", ['when' => 'custom']);

    $response->assertUnprocessable()->assertJsonValidationErrors('datetime');
});

test('watching an episode rejects an invalid when value', function () {
    $user = User::factory()->create();
    $episode = Episode::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/episodes/{$episode->id}/watch", ['when' => 'bogus']);

    $response->assertUnprocessable()->assertJsonValidationErrors('when');
});

test('watching an already-watched episode logs another play', function () {
    $user = User::factory()->create();
    $episode = Episode::factory()->aired()->create();
    Play::factory()->for($episode, 'playable')->create();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/episodes/{$episode->id}/watch")
        ->assertCreated();

    expect($episode->plays()->count())->toBe(2);
});

test('unwatching an episode requires authentication', function () {
    $episode = Episode::factory()->create();

    $response = $this->deleteJson("/api/v1/episodes/{$episode->id}/watch");

    $response->assertUnauthorized();
});

test('unwatching an episode removes the latest manual play', function () {
    $user = User::factory()->create();
    $episode = Episode::factory()->create();
    Play::factory()->for($episode, 'playable')->create([
        'source' => PlaySource::Manual,
        'watched_at' => now()->subDay(),
    ]);
    $latestManual = Play::factory()->for($episode, 'playable')->create([
        'source' => PlaySource::Manual,
        'watched_at' => now(),
    ]);

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/episodes/{$episode->id}/watch");

    $response->assertNoContent();

    expect($episode->plays()->count())->toBe(1)
        ->and(Play::find($latestManual->id))->toBeNull();
});

test('unwatching an episode falls back to the latest play of any source when no manual play exists', function () {
    $user = User::factory()->create();
    $episode = Episode::factory()->create();
    $plexPlay = Play::factory()->for($episode, 'playable')->create(['source' => PlaySource::Plex]);

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/episodes/{$episode->id}/watch");

    $response->assertNoContent();

    expect(Play::find($plexPlay->id))->toBeNull();
});

test('unwatching an episode with no plays returns 404', function () {
    $user = User::factory()->create();
    $episode = Episode::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/episodes/{$episode->id}/watch");

    $response->assertNotFound();
});

test('removing a play by id requires authentication', function () {
    $play = Play::factory()->create();

    $response = $this->deleteJson("/api/v1/plays/{$play->id}");

    $response->assertUnauthorized();
});

test('removing a play by id deletes it', function () {
    $user = User::factory()->create();
    $play = Play::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/plays/{$play->id}");

    $response->assertNoContent();

    expect(Play::find($play->id))->toBeNull();
});

test('removing an unknown play returns 404', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/plays/999999');

    $response->assertNotFound();
});
