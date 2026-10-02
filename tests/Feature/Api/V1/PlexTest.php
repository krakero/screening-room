<?php

use App\Jobs\SyncPlexLibrary;
use App\Models\PlexLibraryItem;
use App\Models\Title;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Queue;

function configurePlexApi(): void
{
    app(IntegrationSettings::class)->setMany([
        'plex.url' => 'https://plex.test',
        'plex.token' => 'secret-plex-token',
    ]);
}

test('plex sync requires authentication', function () {
    $response = $this->postJson('/api/v1/plex/sync');

    $response->assertUnauthorized();
});

test('plex sync dispatches the sync job without blocking', function () {
    Queue::fake();

    $user = User::factory()->create();
    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/plex/sync');

    $response->assertStatus(202)->assertJson(['message' => 'Sync queued.']);

    Queue::assertPushed(SyncPlexLibrary::class);
});

test('plex availability requires authentication', function () {
    $response = $this->getJson('/api/v1/plex/availability?title_id=1');

    $response->assertUnauthorized();
});

test('plex availability requires a title_id or episode_id', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/plex/availability');

    $response->assertUnprocessable()->assertJsonValidationErrors(['title_id', 'episode_id']);
});

test('plex availability reports a matched title from the local library index', function () {
    configurePlexApi();

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    PlexLibraryItem::factory()->create([
        'type' => 'movie',
        'tmdb_id' => 603,
        'plex_rating_key' => '999',
        'machine_identifier' => 'abc123def456',
    ]);

    $user = User::factory()->create();
    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/plex/availability?title_id={$title->id}");

    $response->assertOk()->assertJson(['available' => true]);
    expect($response->json('play_url'))->toContain('abc123def456')->toContain('999');
});

test('plex availability reports unavailable when not found in the index', function () {
    configurePlexApi();

    $title = Title::factory()->movie()->create(['tmdb_id' => 603]);

    $user = User::factory()->create();
    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/plex/availability?title_id={$title->id}");

    $response->assertOk()->assertJson(['available' => false, 'play_url' => null]);
});
