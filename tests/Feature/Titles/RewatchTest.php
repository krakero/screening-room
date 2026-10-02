<?php

use App\Enums\FollowState;
use App\Livewire\EpisodeFlyout;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Livewire\Livewire;

test('the overflow menu shows Restart show for a followed show in every state, and Stop rewatch only while rewatching', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    $follow = Follow::factory()->for($title)->create(['state' => FollowState::Watching]);

    $response = $this->get(route('titles.show', $title));
    $response->assertOk();
    $response->assertSee(__('Restart show'));
    $response->assertDontSee(__('Stop rewatch'));

    $follow->update(['state' => FollowState::Completed]);

    $response = $this->get(route('titles.show', $title));
    $response->assertOk();
    $response->assertSee(__('Restart show'));

    $follow->update(['rewatch_started_at' => now()]);

    $response = $this->get(route('titles.show', $title));
    $response->assertOk();
    $response->assertSee(__('Restart show'));
    $response->assertSee(__('Stop rewatch'));
});

test('the overflow menu has no Restart show row for a show that is not followed', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Follow'));
    $response->assertDontSee(__('Restart show'));
    $response->assertDontSee(__('Stop rewatch'));
});

test('no rewatch badge appears anywhere on the title page while rewatching', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    Follow::factory()->for($title)->rewatching()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    // Not a plain assertDontSee(__('Rewatch')) — that also matches the "stopRewatch"
    // wire:click method name in the markup, which is expected to be there.
    $response->assertDontSee('>Rewatch<', false);
    $response->assertDontSee(__('Rewatching'));
});

test('confirming Restart show starts the show over at S1E1, keeping history', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode1 = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    $episode2 = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 2]);

    Follow::factory()->for($title)->completed()->create();
    Play::factory()->for($episode1, 'playable')->create();
    Play::factory()->for($episode2, 'playable')->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('confirmedRestartShow');

    $follow = $title->follow()->first();

    expect($follow->state)->toBe(FollowState::Watching)
        ->and($follow->isRewatching())->toBeTrue()
        ->and($episode1->plays()->count())->toBe(1)
        ->and($episode2->plays()->count())->toBe(1);
});

test('stopping a rewatch clears the restart date and recomputes state', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    Follow::factory()->for($title)->rewatching()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('stopRewatch');

    $follow = $title->follow()->first();

    expect($follow->rewatch_started_at)->toBeNull()
        ->and($follow->state)->toBe(FollowState::Watching);
});

test('the overflow menu offers Watch again for an already-watched movie, with the same date options', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    Play::factory()->for($title, 'playable')->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Watch again…'));
    $response->assertSeeInOrder([
        __('Watch again…'),
        __('Now'),
        __('On release date'),
        __('Unknown date'),
        __('Pick date & time…'),
    ]);
    $response->assertDontSee(__('Mark watched'));
});

test('the overflow menu still shows plain Mark watched for a never-watched movie', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Mark watched'));
    $response->assertDontSee(__('Watch again…'));
});

test('Watch again on an already-watched movie always adds a new play, twice adds two', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    Play::factory()->for($title, 'playable')->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('markWatched');

    expect($title->plays()->count())->toBe(2);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('markWatched', 'release_date');

    expect($title->plays()->count())->toBe(3);
});

test('the season page episode card offers Watch again alongside Mark unwatched for a watched episode', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create();

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertSee(__('Watch again'));
    $response->assertSee(__('Mark unwatched'));
});

test('Watch again on the season page always adds a new play for the episode, twice adds two', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create();

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('watchAgain', $episode->id);

    expect($episode->plays()->count())->toBe(2);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->call('watchAgain', $episode->id);

    expect($episode->plays()->count())->toBe(3);
});

test('the episode flyout offers Watch again for a watched episode', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create();

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->assertSee(__('Watch again'));
});

test('Watch again from the episode flyout always adds a new play, twice adds two', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);
    Play::factory()->for($episode, 'playable')->create();

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->call('watchAgain');

    expect($episode->plays()->count())->toBe(2);

    Livewire::test(EpisodeFlyout::class, ['episodeId' => $episode->id])
        ->call('watchAgain');

    expect($episode->plays()->count())->toBe(3);
});

test('the season page checkmark shows progress since the restart during a rewatch', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1]);
    $episode = Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1, 'episode_number' => 1]);

    // A play from before the restart shouldn't count as watched during the rewatch. Logging it
    // auto-creates the follow (PlayObserver), so update it afterwards rather than creating a
    // second one.
    Play::factory()->for($episode, 'playable')->create(['watched_at' => now()->subDays(10)]);
    $title->follow()->update(['state' => FollowState::Watching, 'rewatch_started_at' => now()]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertSee('data-optimistic-server-watched="0"', false);
});
