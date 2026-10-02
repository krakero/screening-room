<?php

use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Livewire\Livewire;

test('an aired unwatched next episode is shown with its SxxEyy code', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['in_production' => true]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Up next'));
    $response->assertSee('S01E01');
});

test('an unaired next episode shows its air date instead of watch actions', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['in_production' => true]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->unaired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Up next'));
    $response->assertSee(__('Airs :date', ['date' => $episode->air_date->format('M j, Y')]));
    $response->assertDontSee(__('Mark watched'));
});

test('a fully watched ended show hides the up next section', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['in_production' => false]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee(__('Up next'));
    $response->assertDontSee(__('All caught up'));
});

test('a fully watched returning show shows an all caught up note instead of hiding', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['in_production' => true]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee(__('Up next'));
    $response->assertSee(__('All caught up'));
});

test('a never watched show shows S1E1 as the next episode', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['in_production' => true]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 2]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('S01E01');
    $response->assertDontSee('S01E02');
});

test('the up next card is keyed by the episode id', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['in_production' => true]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('wire:key="up-next-episode-'.$episode->id.'"', false);
});

test('marking the up next episode watched advances the card to the following episode', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['in_production' => true]);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode1 = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    $episode2 = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 2]);

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    expect($component->instance()->nextUpEpisode->id)->toBe($episode1->id);

    $component->call('markUpNextEpisode', $episode1->id);

    expect($episode1->plays()->count())->toBe(1)
        ->and($component->instance()->nextUpEpisode->id)->toBe($episode2->id);
});
