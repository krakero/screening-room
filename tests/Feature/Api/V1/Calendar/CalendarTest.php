<?php

use App\Enums\FollowState;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Support\DisplayTimezone;

function calendarUser(): User
{
    return User::factory()->create(['timezone' => 'UTC']);
}

test('calendar requires authentication', function () {
    $this->getJson('/api/v1/calendar?start=2026-01-01&end=2026-01-31')->assertUnauthorized();
});

test('calendar catch-up requires authentication', function () {
    $this->getJson('/api/v1/calendar/catch-up')->assertUnauthorized();
});

test('calendar validates required start and end params', function () {
    $user = calendarUser();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/calendar');

    $response->assertUnprocessable()->assertJsonValidationErrors(['start', 'end']);
});

test('calendar rejects a range wider than 90 days', function () {
    $user = calendarUser();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/calendar?start=2026-01-01&end=2026-06-01');

    $response->assertUnprocessable()->assertJsonValidationErrors('end');
});

test('calendar returns followed episodes in range and hides paused/abandoned shows', function () {
    $user = calendarUser();
    $today = DisplayTimezone::today();

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => $today->clone()->addDays(3),
    ]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $pausedShow = Title::factory()->show()->create();
    $pausedSeason = Season::factory()->for($pausedShow)->create(['season_number' => 1]);
    Episode::factory()->for($pausedSeason)->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => $today->clone()->addDays(3),
    ]);
    Follow::factory()->for($pausedShow)->paused()->create();

    $start = $today->toDateString();
    $end = $today->clone()->addDays(10)->toDateString();

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/calendar?start={$start}&end={$end}");

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['type'])->toBe('episode');
    expect($data[0]['episode']['id'])->toBe($episode->id);
    expect($data[0]['title']['id'])->toBe($show->id);
});

test('calendar catch-up returns aired unwatched episodes from the last 7 days', function () {
    $user = calendarUser();
    $today = DisplayTimezone::today();

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => $today->clone()->subDays(2),
    ]);
    Follow::factory()->for($show)->create(['state' => FollowState::Watching]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/calendar/catch-up');

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['episode']['id'])->toBe($episode->id);
    expect($data[0]['watched'])->toBeFalse();
});
