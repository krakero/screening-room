<?php

use App\Jobs\RefreshSeasonTrailer;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('it dispatches a season trailer refresh when the season has never been checked', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create([
        'season_number' => 1,
        'trailer_key' => null,
        'trailer_checked_at' => null,
    ]);

    $this->get(route('titles.seasons.show', [$title, 1]))->assertOk()->assertDontSee('@if');

    Queue::assertPushed(RefreshSeasonTrailer::class, fn (RefreshSeasonTrailer $job) => $job->season->is($season));
});

test('it dispatches a season trailer refresh when the last check was more than 30 days ago', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create([
        'season_number' => 1,
        'trailer_key' => null,
        'trailer_checked_at' => now()->subDays(31),
    ]);

    $this->get(route('titles.seasons.show', [$title, 1]))->assertOk();

    Queue::assertPushed(RefreshSeasonTrailer::class);
});

test('it does not dispatch a season trailer refresh when the season already has a trailer', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create([
        'season_number' => 1,
        'trailer_site' => 'YouTube',
        'trailer_key' => 'abc123',
        'trailer_checked_at' => null,
    ]);

    $this->get(route('titles.seasons.show', [$title, 1]))->assertOk();

    Queue::assertNotPushed(RefreshSeasonTrailer::class);
});

test('it does not dispatch a season trailer refresh when the check is recent', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create([
        'season_number' => 1,
        'trailer_key' => null,
        'trailer_checked_at' => now()->subDays(5),
    ]);

    $this->get(route('titles.seasons.show', [$title, 1]))->assertOk();

    Queue::assertNotPushed(RefreshSeasonTrailer::class);
});
