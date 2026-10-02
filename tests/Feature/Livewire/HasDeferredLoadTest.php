<?php

use App\Jobs\RefreshTitleTrailer;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Exercises `App\Livewire\Concerns\HasDeferredLoad` through its real usage on the title show
 * page (the 'show-trailer' key), which is the simplest of the three deferred payloads wired up
 * there: it both dispatches the job and drives its own pending state via `dispatchDeferred()`.
 */
test('the page renders immediately with a skeleton before the deferred job has run', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_key' => null, 'trailer_checked_at' => null]);

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    expect($component->instance()->deferredPending('show-trailer'))->toBeTrue();
    $component->assertSee(__('Checking for trailer…'));
});

test('job completion flips the deferred key to resolved on the next poll', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_key' => null, 'trailer_checked_at' => null]);

    $component = Livewire::test('pages::titles.show', ['title' => $title]);
    expect($component->instance()->deferredPending('show-trailer'))->toBeTrue();

    // Simulate the queued RefreshTitleTrailer job completing.
    $title->update(['trailer_site' => 'YouTube', 'trailer_key' => 'abc123', 'trailer_checked_at' => now()]);

    $component->call('pollTrailers');

    expect($component->instance()->deferredPending('show-trailer'))->toBeFalse();
    $component->assertSee('Watch trailer')->assertDontSee('Checking for trailer…');
});

test('polling gives up after the capped duration even if the job never completes', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_key' => null, 'trailer_checked_at' => null]);

    $component = Livewire::test('pages::titles.show', ['title' => $title]);
    expect($component->instance()->deferredPending('show-trailer'))->toBeTrue();

    // Still well inside the cap: stays pending.
    $component->set('deferredPendingSince.show-trailer', now()->subSeconds(20)->timestamp);
    $component->call('pollTrailers');
    expect($component->instance()->deferredPending('show-trailer'))->toBeTrue();

    // Past the ~30s cap: gives up even though nothing ever resolved it.
    $component->set('deferredPendingSince.show-trailer', now()->subSeconds(31)->timestamp);
    $component->call('pollTrailers');
    expect($component->instance()->deferredPending('show-trailer'))->toBeFalse();
});

test('rapid re-mounts of the same page do not dispatch the deferred job twice', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['trailer_key' => null, 'trailer_checked_at' => null]);

    // Two "concurrent" page loads for the same title, as if two requests landed at once.
    Livewire::test('pages::titles.show', ['title' => $title]);
    Livewire::test('pages::titles.show', ['title' => $title]);

    Queue::assertPushed(RefreshTitleTrailer::class, 1);
});
