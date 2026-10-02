<?php

use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Livewire\Livewire;

test('authenticated users can view a show title page with a season poster row', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'Severance']);
    Season::factory()->for($title)->create(['season_number' => 1, 'name' => 'Season One']);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Severance');
    $response->assertSee(__('Season :number', ['number' => 1]));
    $response->assertSee(route('titles.seasons.show', [$title, 1]), false);
});

test('the show watched button shows a visible text label', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Mark show watched'));
});

test('the show watched button\'s normal and loading labels are both wrapped by the same Alpine scope', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();

    $response = $this->get(route('titles.show', $title));
    $content = $response->getContent();

    $response->assertOk();
    $response->assertDontSee('@if', false);

    // The bug: x-data was placed on <flux:button.group>, which left the split button's
    // x-show="!pending" / x-show="pending" spans evaluating "pending" out of scope, so
    // Alpine errored and left both the normal and loading labels visible at once. The fix
    // wraps the whole button group in a plain <div x-data="..."> ancestor instead.
    $dataPos = strpos($content, 'x-data="{');
    $groupPos = strpos($content, 'data-flux-button-group');
    $labelPos = strpos($content, 'x-show="!pending"');
    $loadingPos = strpos($content, 'x-show="pending"');

    expect($dataPos)->not->toBeFalse()
        ->and($groupPos)->not->toBeFalse()
        ->and($labelPos)->not->toBeFalse()
        ->and($loadingPos)->not->toBeFalse()
        // x-data renders on an ancestor <div> before the button group's own opening tag,
        // not on the button group's tag itself, so it stays in scope through re-renders.
        ->and($dataPos)->toBeLessThan($groupPos)
        ->and($groupPos)->toBeLessThan($labelPos)
        ->and($groupPos)->toBeLessThan($loadingPos);

    // The loading span must carry a hide-until-Alpine-loads attribute.
    $response->assertSee('x-show="pending" x-cloak', false);
});

test('specials are ordered last in the season poster row', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    Season::factory()->for($title)->create(['season_number' => 0]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $season1Position = strpos($response->getContent(), __('Season :number', ['number' => 1]));
    $specialsPosition = strpos($response->getContent(), __('Specials'));

    expect($season1Position)->not->toBeFalse()
        ->and($specialsPosition)->not->toBeFalse()
        ->and($season1Position)->toBeLessThan($specialsPosition);
});

test('season cards fall back to the show poster and show the tmdb episode_count when unloaded', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['poster_path' => '/show.jpg']);
    Season::factory()->notLoaded()->for($title)->create(['season_number' => 1, 'poster_path' => null, 'episode_count' => 9]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__(':count episodes', ['count' => 9]));
});

test('marking a show watched logs plays for every aired unwatched non-special episode', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season1 = Season::factory()->for($title)->create(['season_number' => 1]);
    $season2 = Season::factory()->for($title)->create(['season_number' => 2]);
    $specials = Season::factory()->for($title)->create(['season_number' => 0]);

    $unwatchedSeason1 = Episode::factory()->aired()->for($season1)->create(['title_id' => $title->id, 'season_number' => 1]);
    $unwatchedSeason2 = Episode::factory()->aired()->for($season2)->create(['title_id' => $title->id, 'season_number' => 2]);
    $unaired = Episode::factory()->unaired()->for($season2)->create(['title_id' => $title->id, 'season_number' => 2]);
    $special = Episode::factory()->aired()->for($specials)->create(['title_id' => $title->id, 'season_number' => 0]);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('markShowWatched');

    expect($unwatchedSeason1->plays()->count())->toBe(1)
        ->and($unwatchedSeason2->plays()->count())->toBe(1)
        ->and($unaired->plays()->count())->toBe(0)
        ->and($special->plays()->count())->toBe(0);
});

test('clicking mark show watched opens a confirmation modal without logging plays', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['name' => 'Severance']);
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('confirmMarkShowWatched')
        ->assertSee(__('Mark all :count aired episodes of :title as watched?', ['count' => 1, 'title' => 'Severance']));

    expect($episode->plays()->count())->toBe(0);
});

test('confirming the show watched modal logs plays for aired unwatched episodes', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('confirmMarkShowWatched')
        ->call('confirmedMarkShowWatched');

    expect($episode->plays()->count())->toBe(1);
});

test('progress excludes season 0 from both watched and total counts', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $specials = Season::factory()->for($title)->create(['season_number' => 0]);

    $watched = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);
    Play::factory()->for($watched, 'playable')->create();

    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    $watchedSpecial = Episode::factory()->aired()->for($specials)->create(['title_id' => $title->id, 'season_number' => 0]);
    Play::factory()->for($watchedSpecial, 'playable')->create();

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    expect($component->instance()->progress->watchedCount)->toBe(1)
        ->and($component->instance()->progress->airedCount)->toBe(2);
});
