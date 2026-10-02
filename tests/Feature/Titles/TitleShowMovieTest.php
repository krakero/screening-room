<?php

use App\Enums\PlaySource;
use App\Models\Play;
use App\Models\Title;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertRedirect(route('login'));
});

test('authenticated users can view a movie title page', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['name' => 'Fight Club', 'overview' => 'A depressed man forms a fight club.']);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('Fight Club');
    $response->assertSee('A depressed man forms a fight club.');
});

test('the title page renders a full-bleed hero', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('mx-auto w-full max-w-screen-2xl', false);
    $response->assertSee('data-full-bleed', false);
});

test('the hero content and page body use the shared page container', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('@if', false);
    $response->assertSee('data-flux-container', false);
    $response->assertSee('mx-auto w-full max-w-7xl px-6 lg:px-8', false);
});

test('marking a movie watched now logs a manual play at the current time', function () {
    $this->actingAs(User::factory()->create());

    CarbonImmutable::setTestNow('2026-01-15 20:30');

    $title = Title::factory()->movie()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('markWatched');

    expect($title->plays()->count())->toBe(1);

    $play = $title->plays()->first();

    expect($play->source)->toBe(PlaySource::Manual)
        ->and($play->watched_at->format('Y-m-d H:i'))->toBe('2026-01-15 20:30');

    CarbonImmutable::setTestNow();
});

test('marking a movie watched on its release date uses that date', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create(['release_date' => '2010-03-01']);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('markWatched', 'release_date');

    expect($title->plays()->first()->watched_at->toDateString())->toBe('2010-03-01');
});

test('marking a movie watched as unknown stores a null watched_at', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('markWatched', 'unknown');

    expect($title->plays()->first()->watched_at)->toBeNull();
});

test('the watched button shows a visible text label', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Mark watched'));
});

test('the watched button sits in the hero, not below the overview', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create([
        'name' => 'Fight Club',
        'release_date' => '1999-10-15',
        'overview' => 'A depressed man forms a fight club.',
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    // "Mark watched" now lives in the title row's "…" overflow menu (per TL-6), which renders
    // before the meta-chips row in the DOM — so it comes before the year chip, not after.
    $response->assertSeeInOrder([
        'Fight Club',
        __('Mark watched'),
        '1999',
        'A depressed man forms a fight club.',
    ]);
});

test('the "Pick date & time…" menu item opens the picker client-side, with no wire:click', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee('x-on:click="customDatetimeTarget = &#039;movie&#039;; customDatetime = window.nowInDisplayTimezone(document.documentElement.dataset.timezone); $flux.modal(&#039;custom-watched-at&#039;).show()"', false);
    $response->assertDontSee('wire:click="openCustomDatetime(&#039;movie&#039;)"', false);
    $response->assertSee('data-modal="custom-watched-at"', false);
});

test('the custom-datetime modal Save closes instantly and calls confirmCustomDatetime through $wire, not wire:submit', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee('wire:submit="confirmCustomDatetime(customDatetimeTarget, customDatetime)"', false);
    $response->assertSee("\$flux.modal('custom-watched-at').close();", false);
    $response->assertSee('$wire.confirmCustomDatetime(customDatetimeTarget, customDatetime).catch', false);
});

test('marking a movie watched with a custom datetime opens a modal then logs at that time', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'UTC']));

    $title = Title::factory()->movie()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('confirmCustomDatetime', 'movie', '2015-07-04T09:00');

    expect($title->plays()->first()->watched_at->format('Y-m-d H:i'))->toBe('2015-07-04 09:00');
});

test('a custom datetime is interpreted in the user\'s timezone and stored as UTC', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));

    $title = Title::factory()->movie()->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('confirmCustomDatetime', 'movie', '2015-07-04T09:00');

    // 9am in America/New_York (EDT, UTC-4) on 2015-07-04 is 13:00 UTC.
    expect($title->plays()->first()->watched_at->format('Y-m-d H:i'))->toBe('2015-07-04 13:00');
});

test('a manual play can be removed from a movie without confirmation required server-side', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    $play = Play::factory()->for($title, 'playable')->create(['source' => PlaySource::Manual]);

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('removePlay', $play->id);

    expect(Play::query()->find($play->id))->toBeNull();
});

test('removing a play only affects plays belonging to the viewed title', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();
    $otherTitle = Title::factory()->movie()->create();
    $otherPlay = Play::factory()->for($otherTitle, 'playable')->create();

    Livewire::test('pages::titles.show', ['title' => $title])
        ->call('removePlay', $otherPlay->id)
        ->assertStatus(404);

    expect(Play::query()->find($otherPlay->id))->not->toBeNull();
});
