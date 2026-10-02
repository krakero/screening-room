<?php

use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('the current season trailer is offered, labelled Season N trailer, when only the season has one', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['trailer_site' => null, 'trailer_key' => null, 'trailer_checked_at' => now()]);
    $season = Season::factory()->for($title)->create([
        'season_number' => 2,
        'trailer_site' => 'YouTube',
        'trailer_key' => 'season-key',
        'trailer_checked_at' => now(),
    ]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 2]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('Season 2 trailer');
    $response->assertDontSee('Watch trailer');
});

test('it offers both a Show trailer and a Season N trailer option when both exist', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create([
        'trailer_site' => 'YouTube',
        'trailer_key' => 'show-key',
        'trailer_checked_at' => now(),
    ]);
    $season = Season::factory()->for($title)->create([
        'season_number' => 1,
        'trailer_site' => 'YouTube',
        'trailer_key' => 'season-key',
        'trailer_checked_at' => now(),
    ]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('Show trailer');
    $response->assertSee('Season 1 trailer');
});

test('it falls back to the show trailer when the current season has none', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create([
        'trailer_site' => 'YouTube',
        'trailer_key' => 'show-key',
        'trailer_checked_at' => now(),
    ]);
    $season = Season::factory()->for($title)->create([
        'season_number' => 1,
        'trailer_site' => null,
        'trailer_key' => null,
        'trailer_checked_at' => now(),
    ]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Watch trailer');
    $response->assertDontSee('Season 1 trailer');
});

test('it prefers the latest aired season once every aired episode is watched', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['trailer_site' => null, 'trailer_key' => null, 'trailer_checked_at' => now()]);

    $seasonOne = Season::factory()->for($title)->create([
        'season_number' => 1,
        'air_date' => now()->subYears(2),
        'trailer_checked_at' => now(),
    ]);
    $episodeOne = Episode::factory()->aired()->for($seasonOne)->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($episodeOne, 'playable')->create();

    $seasonTwo = Season::factory()->for($title)->create([
        'season_number' => 2,
        'air_date' => now()->subYear(),
        'trailer_site' => 'YouTube',
        'trailer_key' => 'season-two-key',
        'trailer_checked_at' => now(),
    ]);
    $episodeTwo = Episode::factory()->aired()->for($seasonTwo)->create(['title_id' => $title->id, 'season_number' => 2]);
    Play::factory()->for($episodeTwo, 'playable')->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Season 2 trailer');
});
