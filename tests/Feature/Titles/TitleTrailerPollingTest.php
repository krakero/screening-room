<?php

use App\Models\Episode;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('pollTrailers clears the show-trailer pending state once the trailer has been stamped, and shows the button', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_key' => null, 'trailer_checked_at' => null]);

    Queue::fake();

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    expect($component->instance()->deferredPending('show-trailer'))->toBeTrue();
    $component->assertSee('Checking for trailer…');

    // Simulate the queued RefreshTitleTrailer job completing.
    $title->update(['trailer_site' => 'YouTube', 'trailer_key' => 'abc123', 'trailer_checked_at' => now()]);

    $component->call('pollTrailers');

    expect($component->instance()->deferredPending('show-trailer'))->toBeFalse();
    $component->assertSee('Watch trailer')->assertDontSee('Checking for trailer…');
});

test('pollTrailers stops polling after ~30 seconds even without a stamp', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_key' => null, 'trailer_checked_at' => null]);

    Queue::fake();

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    expect($component->instance()->deferredPending('show-trailer'))->toBeTrue();

    $component->set('deferredPendingSince.show-trailer', now()->subSeconds(31)->timestamp);
    $component->call('pollTrailers');

    expect($component->instance()->deferredPending('show-trailer'))->toBeFalse();
});

test('pollTrailers also clears the season-trailer pending state once the season trailer has been stamped', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create(['trailer_site' => null, 'trailer_key' => null, 'trailer_checked_at' => now()]);
    $season = Season::factory()->for($title)->create(['season_number' => 1, 'trailer_key' => null, 'trailer_checked_at' => null]);
    Episode::factory()->aired()->for($season)->create(['title_id' => $title->id, 'season_number' => 1]);

    Queue::fake();

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    expect($component->instance()->deferredPending('season-trailer'))->toBeTrue();

    $season->update(['trailer_site' => 'YouTube', 'trailer_key' => 'season-key', 'trailer_checked_at' => now()]);

    $component->call('pollTrailers');

    expect($component->instance()->deferredPending('season-trailer'))->toBeFalse();
    $component->assertSee('Season 1 trailer');
});
