<?php

use App\Jobs\RefreshTitleRatings;
use App\Models\ExternalRating;
use App\Models\Title;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    app(IntegrationSettings::class)->set('mdblist.api_key', 'test-key');
    Queue::fake();
});

test('a stale title dispatches the ratings fetch and shows a polling placeholder', function () {
    $title = Title::factory()->movie()->create(['ratings_checked_at' => null]);

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    Queue::assertPushed(RefreshTitleRatings::class);
    expect($component->instance()->deferredPending('ratings'))->toBeTrue();
    $component->assertSeeHtml("wire:poll.3s=\"pollDeferred('ratings')\"");
});

test('polling re-renders the rating badges once the job has stamped ratings_checked_at', function () {
    $title = Title::factory()->movie()->create(['ratings_checked_at' => null]);

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    // Simulate the queued RefreshTitleRatings job completing.
    ExternalRating::factory()->for($title)->create(['source' => 'imdb', 'value' => 8.7, 'max' => 10]);
    $title->forceFill(['ratings_checked_at' => now()])->save();

    $component->call('pollDeferred', 'ratings');

    expect($component->instance()->deferredPending('ratings'))->toBeFalse();
    $component->assertSee('IMDb')->assertSee('8.7')->assertDontSeeHtml("pollDeferred('ratings')");
});

test('polling stops when the job finishes even with zero ratings', function () {
    $title = Title::factory()->movie()->create(['ratings_checked_at' => null]);

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    $title->forceFill(['ratings_checked_at' => now()])->save();
    $component->call('pollDeferred', 'ratings');

    expect($component->instance()->deferredPending('ratings'))->toBeFalse();
    $component->assertDontSeeHtml("pollDeferred('ratings')");
});

test('a stopped worker stops the ratings poll after the cap', function () {
    $title = Title::factory()->movie()->create(['ratings_checked_at' => null]);

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    $component->call('pollDeferred', 'ratings');
    expect($component->instance()->deferredPending('ratings'))->toBeTrue();

    $component->set('deferredPendingSince.ratings', now()->subSeconds(31)->timestamp);
    $component->call('pollDeferred', 'ratings');

    expect($component->instance()->deferredPending('ratings'))->toBeFalse();
});

test('fresh ratings do not poll', function () {
    $title = Title::factory()->movie()->create(['ratings_checked_at' => now()]);

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    Queue::assertNotPushed(RefreshTitleRatings::class);
    expect($component->instance()->deferredPending('ratings'))->toBeFalse();
});

test('an unconfigured MDBList never polls', function () {
    app(IntegrationSettings::class)->set('mdblist.api_key', '');
    $title = Title::factory()->movie()->create(['ratings_checked_at' => null]);

    $component = Livewire::test('pages::titles.show', ['title' => $title]);

    expect($component->instance()->deferredPending('ratings'))->toBeFalse();
    $component->assertDontSeeHtml("pollDeferred('ratings')");
});
