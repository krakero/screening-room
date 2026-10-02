<?php

use App\Jobs\RefreshTitleFromTmdb;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

test('refresh requires authentication', function () {
    $title = Title::factory()->movie()->create();

    $this->postJson("/api/v1/titles/{$title->id}/refresh")
        ->assertUnauthorized();
});

test('it queues a forced tmdb refresh and returns 202', function () {
    Bus::fake();

    $this->actingAs(User::factory()->create(), 'sanctum');

    $title = Title::factory()->show()->create();

    $response = $this->postJson("/api/v1/titles/{$title->id}/refresh");

    $response->assertStatus(202);

    Bus::assertDispatched(RefreshTitleFromTmdb::class, fn (RefreshTitleFromTmdb $job): bool => $job->titleId === $title->id);
});
