<?php

use App\Enums\FollowState;
use App\Models\Follow;
use App\Models\Title;
use App\Models\User;
use App\Services\Stats\StatsCacheVersion;

test('the follow endpoints require authentication', function () {
    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->create();

    $this->postJson("/api/v1/titles/{$title->id}/follow")->assertUnauthorized();
    $this->postJson("/api/v1/follows/{$follow->id}/pause")->assertUnauthorized();
    $this->postJson("/api/v1/follows/{$follow->id}/resume")->assertUnauthorized();
});

test('following a show creates a watching follow', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/follow");

    $response->assertCreated()->assertJson(['follow' => ['state' => 'watching']]);
    expect(Follow::query()->where('title_id', $title->id)->first())
        ->state->toBe(FollowState::Watching);
});

test('following an already-watching show is idempotent', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/follow");

    $response->assertCreated()->assertJson(['follow' => ['id' => $follow->id, 'state' => 'watching']]);
    expect(Follow::query()->where('title_id', $title->id)->count())->toBe(1);
});

test('following a paused show resumes it to watching', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    $follow = Follow::factory()->for($title)->paused()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/follow");

    $response->assertCreated()->assertJson(['follow' => ['id' => $follow->id, 'state' => 'watching']]);
});

test('a follow can be paused', function () {
    $user = User::factory()->create();
    $follow = Follow::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/follows/{$follow->id}/pause");

    $response->assertOk()->assertJson(['follow' => ['id' => $follow->id, 'state' => 'paused']]);
    expect($follow->refresh()->state)->toBe(FollowState::Paused);
});

test('a paused follow can be resumed', function () {
    $user = User::factory()->create();
    $follow = Follow::factory()->paused()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/follows/{$follow->id}/resume");

    $response->assertOk()->assertJson(['follow' => ['id' => $follow->id, 'state' => 'watching']]);
    expect($follow->refresh()->state)->toBe(FollowState::Watching);
});

test('an unknown title returns a 404 when following', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/titles/999999/follow')->assertNotFound();
});

test('an unknown follow returns a 404', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/follows/999999/pause')->assertNotFound();
});

test('following a show bumps the stats cache version', function () {
    $user = User::factory()->create();
    $title = Title::factory()->show()->create();
    $before = app(StatsCacheVersion::class)->current();

    $this->actingAs($user, 'sanctum')->postJson("/api/v1/titles/{$title->id}/follow");

    expect(app(StatsCacheVersion::class)->current())->toBeGreaterThan($before);
});
