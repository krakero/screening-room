<?php

use App\Enums\FollowState;
use App\Models\CollectionItem;
use App\Models\Episode;
use App\Models\Follow;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\Stats\StatsCacheVersion;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('stats'));

    $response->assertRedirect(route('login'));
});

test('the page renders a skeleton before loadStats runs, without leaking blade directives', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('stats'));

    $response->assertOk();
    $response->assertDontSee('@if');
    $response->assertDontSee(__('Nothing watched yet'));
});

test('an empty library shows an empty state once loaded', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::stats')->call('loadStats');

    $component->assertSee(__('Nothing watched yet'));
});

test('headline tiles summarize watch time, counts, and follows', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['runtime' => 120]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'runtime' => 30]);
    Play::factory()->for($episode, 'playable')->create(['watched_at' => now()]);

    // PlayObserver auto-follows $show when the episode play above is logged, so only add the completed one explicitly.
    Follow::factory()->create(['state' => FollowState::Completed]);

    $component = Livewire::test('pages::stats')->call('loadStats');

    $component->assertSee('2 h 30 m');
    $component->assertSee(__('Movies watched'));
    $component->assertDontSee('@if');
});

test('the monthly plays chart renders 24 columns, the legend, and a tooltip for the seeded month', function () {
    // Freeze time mid-month to avoid timezone-dependent failures around month boundaries
    Carbon\Carbon::setTestNow('2026-09-15 12:00:00');

    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($movie, 'playable')->count(3)->create(['watched_at' => now()]);

    $show = Title::factory()->show()->create();
    $season = Season::factory()->for($show)->create(['season_number' => 1]);
    $episode = Episode::factory()->for($season)->create(['title_id' => $show->id, 'season_number' => 1, 'runtime' => 30]);
    Play::factory()->for($episode, 'playable')->count(2)->create(['watched_at' => now()]);

    $component = Livewire::test('pages::stats')->call('loadStats');

    $component->assertDontSee('@if');
    $component->assertSee(__('Episodes'));
    $component->assertSee(__('Movies'));

    $html = $component->html();
    $chartPosition = strpos($html, 'data-testid="monthly-plays-chart"');
    $episodesPosition = strpos($html, __(':n episodes', ['n' => 2]));
    $moviesPosition = strpos($html, __(':n movies', ['n' => 3]));

    expect($chartPosition)->not->toBeFalse()
        ->and($episodesPosition)->toBeGreaterThan($chartPosition)
        ->and($moviesPosition)->toBeGreaterThan($episodesPosition);

    $component->assertSee(now()->format('M Y'));
    $component->assertSee(__(':n total', ['n' => 5]));

    expect(substr_count($html, 'group/bar'))->toBe(24);
});

test('the year selector filters headline stats to that year', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => '2024-06-01 12:00:00']);

    $otherMovie = Title::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($otherMovie, 'playable')->create(['watched_at' => '2025-06-01 12:00:00']);

    $component = Livewire::test('pages::stats', ['year' => 2024])->call('loadStats');

    $component->assertSee('1 h 40 m');
});

test('changing the year selector reloads the summary without a second wire:init', function () {
    $this->actingAs(User::factory()->create());

    $movie2024 = Title::factory()->movie()->create(['runtime' => 100]);
    Play::factory()->for($movie2024, 'playable')->create(['watched_at' => '2024-06-01 12:00:00']);

    $movie2025 = Title::factory()->movie()->create(['runtime' => 50]);
    Play::factory()->for($movie2025, 'playable')->create(['watched_at' => '2025-06-01 12:00:00']);

    $component = Livewire::test('pages::stats')->call('loadStats');
    $component->assertSee('2 h 30 m'); // all-time total: 100 + 50 minutes

    $component->set('year', 2024);

    $component->assertSee('1 h 40 m')->assertDontSee('2 h 30 m');
});

test('a summary cached by older code in a different shape is not read back', function () {
    $this->actingAs(User::factory()->create());

    $movie = Title::factory()->movie()->create(['runtime' => 120]);
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);

    $version = app(StatsCacheVersion::class)->current();
    Cache::put("stats:summary:all:v{$version}", ['monthlyPlays' => []], now()->addDay());

    $component = Livewire::test('pages::stats')->call('loadStats');

    $component->assertDontSee('@if');
});

test('the collection section is hidden when collection is disabled', function () {
    $user = User::factory()->create(['collection_enabled' => false]);
    $this->actingAs($user);

    $movie = Title::factory()->movie()->create();
    Play::factory()->for($movie, 'playable')->create(['watched_at' => now()]);

    $component = Livewire::test('pages::stats')->call('loadStats');

    $component->assertDontSee(__('Collection'));
    $component->assertDontSee(__('Your physical and digital media library.'));
});

test('the collection section is shown when collection is enabled and has items', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);

    $title = Title::factory()->movie()->create();
    CollectionItem::factory()->for($title)->create();
    Play::factory()->for($title, 'playable')->create(['watched_at' => now()]);

    $component = Livewire::test('pages::stats')->call('loadStats');

    $component->assertSee(__('Collection'));
    $component->assertSee(__('Your physical and digital media library.'));
    $component->assertSee(__('Total copies'));
});
