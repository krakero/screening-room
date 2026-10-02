<?php

use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('history requires authentication', function () {
    $response = $this->getJson('/api/v1/history');

    $response->assertUnauthorized();
});

test('history returns paginated plays, most recent first, with movie and episode shapes', function () {
    $user = User::factory()->create();

    $movie = Title::factory()->movie()->create(['name' => 'A Movie']);
    $moviePlay = Play::factory()->for($movie, 'playable')->create(['watched_at' => now()->subDay()]);

    $show = Title::factory()->show()->create(['name' => 'A Show']);
    $season = Season::factory()->for($show)->create();
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'name' => 'Pilot']);
    $episodePlay = Play::factory()->for($episode, 'playable')->create(['watched_at' => now()]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/history');

    $response->assertOk()->assertJsonStructure([
        'data',
        'links' => ['first', 'last', 'prev', 'next'],
        'meta' => ['current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total'],
    ]);

    $data = $response->json('data');

    expect($data)->toHaveCount(2)
        ->and($data[0]['id'])->toBe($episodePlay->id)
        ->and($data[0]['playable_type'])->toBe('episode')
        ->and($data[0]['episode']['id'])->toBe($episode->id)
        ->and($data[0]['episode']['title']['id'])->toBe($show->id)
        ->and($data[1]['id'])->toBe($moviePlay->id)
        ->and($data[1]['playable_type'])->toBe('movie')
        ->and($data[1]['title']['id'])->toBe($movie->id);
});

test('history paginates 20 per page', function () {
    $user = User::factory()->create();
    Play::factory()->count(25)->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/history');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(20)
        ->and($response->json('meta.total'))->toBe(25);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/history?page=2');
    expect($response->json('data'))->toHaveCount(5);
});

test('history returns raw UTC watched_at regardless of the viewer timezone, so the client can group by local day itself', function () {
    // Per the contract, day-grouping for /history happens client-side using watched_at + GET /me's
    // timezone (unlike calendar day-grouping keys, which the API computes server-side) — a
    // Kiritimati (UTC+14) viewer should still see the exact stored UTC instant here, unadjusted.
    $user = User::factory()->create(['timezone' => 'Pacific/Kiritimati']);

    Play::factory()->create(['watched_at' => '2024-01-01 23:00:00']);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/history');

    $response->assertOk();
    expect($response->json('data.0.watched_at'))->toBe('2024-01-01T23:00:00Z');
});

test('history query count does not grow with the number of mixed movie and episode plays', function () {
    $user = User::factory()->create();

    $seedPlays = function (int $count): void {
        Title::factory()->movie()->count($count)->create()->each(
            fn (Title $title) => Play::factory()->for($title, 'playable')->create()
        );

        Episode::factory()->count($count)->create()->each(
            fn (Episode $episode) => Play::factory()->for($episode, 'playable')->create()
        );
    };

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $countQueries = function () use ($user, &$queryCount): int {
        $queryCount = 0;
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/history')->assertOk();

        return $queryCount;
    };

    $seedPlays(3);
    $queriesForThree = $countQueries();

    $seedPlays(6);
    $queriesForNine = $countQueries();

    expect($queriesForNine)->toBeLessThanOrEqual($queriesForThree);
});
