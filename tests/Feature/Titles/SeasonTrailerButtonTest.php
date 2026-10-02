<?php

use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('the season page has no Watch trailer button when there is no trailer', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1, 'trailer_site' => null, 'trailer_key' => null]);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertDontSee('Watch trailer');
});

test('the season page shows a Watch trailer button when a trailer is set', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1, 'trailer_site' => 'YouTube', 'trailer_key' => 'abc123']);

    $response = $this->get(route('titles.seasons.show', [$title, 1]));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertSee('Watch trailer');
    $response->assertSee('Open on YouTube');
});

test('the season trailer modal only renders the embed while open', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1, 'trailer_site' => 'YouTube', 'trailer_key' => 'abc123']);

    Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1])
        ->assertDontSee('youtube-nocookie.com/embed/abc123')
        ->call('openTrailer')
        ->assertSee('youtube-nocookie.com/embed/abc123')
        ->call('closeTrailer')
        ->assertDontSee('youtube-nocookie.com/embed/abc123');
});

test('checkTrailer clears the awaiting flag once the lookup has been stamped, and shows the button', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'trailer_key' => null, 'trailer_checked_at' => null]);

    Queue::fake();

    $component = Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1]);

    expect($component->get('awaitingTrailer'))->toBeTrue();
    $component->assertSee('Checking for trailer…');

    $checkingPos = strpos($component->html(), 'Checking for trailer…');
    expect($checkingPos)->not->toBeFalse();
    expect(substr($component->html(), max(0, $checkingPos - 1500), 1500))->toContain('animate-spin');

    // Simulate the queued RefreshSeasonTrailer job completing.
    $season->update(['trailer_site' => 'YouTube', 'trailer_key' => 'abc123', 'trailer_checked_at' => now()]);

    $component->call('checkTrailer');

    expect($component->get('awaitingTrailer'))->toBeFalse();
    $component->assertSee('Watch trailer')->assertDontSee('Checking for trailer…');
});

test('checkTrailer stops polling after ~30 seconds even without a stamp', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1, 'trailer_key' => null, 'trailer_checked_at' => null]);

    Queue::fake();

    $component = Livewire::test('pages::titles.seasons.show', ['title' => $title, 'seasonNumber' => 1]);

    expect($component->get('awaitingTrailer'))->toBeTrue();

    $component->set('awaitingTrailerDispatchedAt', now()->subSeconds(31)->timestamp);
    $component->call('checkTrailer');

    expect($component->get('awaitingTrailer'))->toBeFalse();
});
