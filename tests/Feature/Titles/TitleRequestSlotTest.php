<?php

use App\Enums\LibraryState;
use App\Enums\SeerrRequestStatus;
use App\Jobs\SubmitTitleRequest;
use App\Models\Episode;
use App\Models\LibraryStatus;
use App\Models\PlexLibraryItem;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * UI-11: the Seerr "Request" control now lives in the title row's "Watch on Plex" slot
 * (first item, before Trailer/"…"), replacing the old always-visible meta-chips-row badge. The
 * slot's precedence: on Plex → Watch on Plex; Plex still resolving → "Checking Plex…"; not on
 * Plex and Seerr configured → Request (or the requested/failed states); Seerr not
 * configured and not on Plex → nothing.
 */
function configureSeerrForRequestSlot(): void
{
    app(IntegrationSettings::class)->setMany([
        'seerr.url' => 'https://seerr.test',
        'seerr.api_key' => 'secret-key',
    ]);
}

test('a movie not on Plex with Seerr configured shows Request in the slot', function () {
    $this->actingAs(User::factory()->create());
    configureSeerrForRequestSlot();
    Http::preventStrayRequests();

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Request'));
    $response->assertDontSee(__('Watch on Plex'));
});

test('a show not on Plex with Seerr configured shows Request seasons… in the slot', function () {
    $this->actingAs(User::factory()->create());
    configureSeerrForRequestSlot();
    Http::preventStrayRequests();

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Request seasons…'));
    $response->assertDontSee(__('Watch on Plex'));
});

test('a title on Plex shows Watch on Plex in the slot, never Request, even when Seerr is configured', function () {
    $this->actingAs(User::factory()->create());
    configureSeerrForRequestSlot();
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();

    PlexLibraryItem::factory()->create([
        'plex_rating_key' => '501',
        'machine_identifier' => 'abc123def456',
        'type' => 'movie',
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
    ]);

    $title = Title::factory()->movie()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Watch on Plex'));
    $response->assertDontSee(__('Request'));
});

test('a show still resolving Plex shows "Checking Plex…" in the slot, not Request, even when Seerr is configured', function () {
    $this->actingAs(User::factory()->create());
    configureSeerrForRequestSlot();
    app(IntegrationSettings::class)->setMany(['plex.url' => 'http://plex.local:32400', 'plex.token' => 'token']);
    Http::preventStrayRequests();
    Queue::fake();

    $title = Title::factory()->show()->create(['tmdb_id' => 603, 'imdb_id' => 'tt0133093']);
    $season = Season::factory()->for($title, 'title')->create(['season_number' => 1]);
    Episode::factory()->for($title, 'title')->for($season, 'season')->create([
        'season_number' => 1,
        'episode_number' => 1,
        'air_date' => now()->subDay(),
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Checking Plex…'));
    $response->assertDontSee(__('Request'));
    $response->assertDontSee(__('Watch on Plex'));
});

test('a requested movie shows a disabled Pending approval state, not the Request trigger', function () {
    $this->actingAs(User::factory()->create());
    configureSeerrForRequestSlot();
    Http::preventStrayRequests();

    $title = Title::factory()->movie()->create();
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Requested]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Pending approval'));
    $response->assertDontSee(__('Request seasons…'));
});

test('an approved request shows a disabled Approved state', function () {
    $this->actingAs(User::factory()->create());
    configureSeerrForRequestSlot();
    Http::preventStrayRequests();

    $title = Title::factory()->movie()->create();
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Requested,
        'seerr_status' => SeerrRequestStatus::Approved,
    ]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Approved'));
});

test('a declined request shows Declined · Request again in the slot', function () {
    $this->actingAs(User::factory()->create());
    configureSeerrForRequestSlot();
    Http::preventStrayRequests();

    $title = Title::factory()->movie()->create();
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Declined]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Declined · Request again'));
});

test('a failed request shows Request failed · Retry in the slot', function () {
    $this->actingAs(User::factory()->create());
    configureSeerrForRequestSlot();
    Http::preventStrayRequests();

    $title = Title::factory()->movie()->create();
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Failed]);

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertSee(__('Request failed · Retry'));
});

test('a title not on Plex with Seerr unconfigured shows nothing in the slot', function () {
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk();
    $response->assertDontSee(__('Request'));
    $response->assertDontSee(__('Watch on Plex'));
});

test('clicking Request dispatches SubmitTitleRequest with the chosen seasons and makes no synchronous Seerr call', function () {
    $this->actingAs(User::factory()->create());
    configureSeerrForRequestSlot();
    Http::preventStrayRequests();
    Queue::fake();

    $title = Title::factory()->show()->create();
    $season1 = Season::factory()->for($title)->create(['season_number' => 1]);
    Season::factory()->for($title)->create(['season_number' => 2]);

    Livewire::test('request-title', ['title' => $title])
        ->set('selectedSeasons', [$season1->season_number])
        ->call('request')
        ->assertHasNoErrors();

    expect($title->fresh()->libraryStatus->state)->toBe(LibraryState::Pending);

    Queue::assertPushed(SubmitTitleRequest::class, function (SubmitTitleRequest $job) use ($title, $season1) {
        return $job->title->is($title) && $job->seasonNumbers === [$season1->season_number];
    });

    Http::assertNothingSent();
});
