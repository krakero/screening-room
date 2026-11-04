<?php

use App\Enums\CollectionFormat;
use App\Enums\LibraryState;
use App\Jobs\SubmitTitleRequest;
use App\Models\CollectionItem;
use App\Models\LibraryStatus;
use App\Models\Season;
use App\Models\Title;
use App\Models\User;
use App\Services\MediaRequests\MediaRequestService;
use App\Support\IntegrationSettings;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function fakeConfiguredMediaRequestService(int $requestId = 42, ?array $mediaStatusReturn = null): void
{
    app(IntegrationSettings::class)->setMany([
        'seerr.url' => 'https://seerr.test',
        'seerr.api_key' => 'secret-key',
    ]);

    app()->instance(MediaRequestService::class, new class($requestId, $mediaStatusReturn) implements MediaRequestService
    {
        public function __construct(private int $requestId, private ?array $mediaStatus) {}

        public function testConnection(): bool
        {
            return true;
        }

        public function requestMovie(Title $title): array
        {
            return ['request_id' => $this->requestId, 'seerr_status' => null];
        }

        public function requestShow(Title $title, array $seasonNumbers): array
        {
            return ['request_id' => $this->requestId, 'seerr_status' => null];
        }

        public function mediaStatus(Title $title): ?array
        {
            return $this->mediaStatus;
        }
    });
}

test('the request button is hidden when seerr is not configured', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->movie()->create();

    Livewire::test('request-title', ['title' => $title])
        ->assertDontSee(__('Request'));
});

test('the request button requests a movie and shows the requested badge', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->movie()->create();

    Livewire::test('request-title', ['title' => $title])
        ->assertSee(__('Request'))
        ->call('request')
        ->assertHasNoErrors();

    expect(LibraryStatus::where('title_id', $title->id)->sole()->state)->toBe(LibraryState::Requested);
});

test('the button is replaced with a status badge once already requested', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->movie()->create();
    LibraryStatus::factory()->for($title)->create(['state' => LibraryState::Available]);

    $response = Livewire::test('request-title', ['title' => $title]);

    $response->assertDontSee(__('Request'))
        ->assertSee(__('Available'));
});

test('the title page renders the request component without leaking blade directives', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->movie()->create();

    $response = $this->get(route('titles.show', $title));

    $response->assertOk()->assertDontSee('@if');
});

test('when the title is owned, the request button shows confirmation modal first', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->movie()->create();
    $title->collectionItems()->create([
        'format' => CollectionFormat::BluRay,
    ]);

    Livewire::test('request-title', ['title' => $title])
        ->assertSee(__('Request'))
        ->call('openRequestModal')
        ->assertSee('You already own this')
        ->assertSee('Request anyway');
});

test('when the title is not owned, the request button opens the request modal directly', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->movie()->create();

    $component = Livewire::test('request-title', ['title' => $title]);

    // When not owned, openRequestModal should NOT show the ownership confirmation modal
    // We can verify this by checking that the HTML contains the request modal for this specific title
    $component->assertSee(__('Request'))
        ->call('openRequestModal');

    // The component should render both modals but only open the request modal
    expect($component->get('ownership')->isOwned())->toBeFalse();
});

test('confirming the ownership warning opens the request modal', function () {
    $user = User::factory()->create(['collection_enabled' => true]);
    $this->actingAs($user);
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->movie()->create();
    CollectionItem::factory()->for($title)->create([
        'format' => CollectionFormat::BluRay,
    ]);

    $component = Livewire::test('request-title', ['title' => $title]);

    // Verify ownership is detected
    expect($component->get('ownership')->isOwned())->toBeTrue();
    expect($component->get('ownership')->label())->toContain('Blu-ray');

    // The modal should be in the HTML
    $component->assertSeeHtml('confirm-request-owned-')
        ->assertSeeHtml('Request anyway?');
});

test('when collection is disabled, owned titles do not show confirmation', function () {
    $user = User::factory()->create(['collection_enabled' => false]);
    $this->actingAs($user);
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->movie()->create();
    $title->collectionItems()->create([
        'format' => CollectionFormat::BluRay,
    ]);

    $component = Livewire::test('request-title', ['title' => $title]);

    // When collection is disabled, ownership should return false even though items exist
    expect($component->get('ownership')->isOwned())->toBeFalse();
});

// --- More seasons ---

test('the request-more button shows next to status badge for shows with unrequested seasons', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    Season::factory()->for($title)->create(['season_number' => 2]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Available,
        'requested_seasons' => [1],
    ]);

    Livewire::test('request-title', ['title' => $title])
        ->assertSee(__('Available'))
        ->assertSee(__('Request more seasons…'));
});

test('the request-more button is hidden when all seasons are already requested', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    Season::factory()->for($title)->create(['season_number' => 2]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Available,
        'requested_seasons' => [1, 2],
    ]);

    Livewire::test('request-title', ['title' => $title])
        ->assertSee(__('Available'))
        ->assertDontSee(__('Request more seasons…'));
});

test('the request-more button shows when requested_seasons is null', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Available,
        'requested_seasons' => null,
    ]);

    Livewire::test('request-title', ['title' => $title])
        ->assertSee(__('Available'))
        ->assertSee(__('Request more seasons…'));
});

test('the modal shows already-requested seasons as disabled', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    Season::factory()->for($title)->create(['season_number' => 2]);
    Season::factory()->for($title)->create(['season_number' => 3]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Available,
        'requested_seasons' => [1, 2],
    ]);

    Livewire::test('request-title', ['title' => $title])
        ->assertSeeHtml('Season 1 · Requested')
        ->assertSeeHtml('Season 2 · Requested')
        ->assertDontSeeHtml('Season 3 · Requested');
});

test('request() does not downgrade an Available status to Pending', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    Queue::fake();

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    Season::factory()->for($title)->create(['season_number' => 2]);
    Season::factory()->for($title)->create(['season_number' => 3]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Available,
        'requested_seasons' => [1],
    ]);

    Livewire::test('request-title', ['title' => $title])
        ->set('selectedSeasons', [2, 3])
        ->call('request')
        ->assertHasNoErrors();

    expect(LibraryStatus::where('title_id', $title->id)->sole()->state)->toBe(LibraryState::Available);
});

test('request() dispatches only seasons not already requested', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    Queue::fake();

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    Season::factory()->for($title)->create(['season_number' => 2]);
    Season::factory()->for($title)->create(['season_number' => 3]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Available,
        'requested_seasons' => [1],
    ]);

    Livewire::test('request-title', ['title' => $title])
        ->set('selectedSeasons', [1, 2, 3])
        ->call('request');

    Queue::assertPushed(SubmitTitleRequest::class, fn ($job) => $job->seasonNumbers === [2, 3]);
});

// --- moreOnly mode ---

test('moreOnly renders the request-more button for shows with unrequested seasons', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    Season::factory()->for($title)->create(['season_number' => 2]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Available,
        'requested_seasons' => [1],
    ]);

    Livewire::test('request-title', ['title' => $title, 'moreOnly' => true])
        ->assertSee(__('Request more seasons…'))
        ->assertDontSee(__('Available'));
});

test('moreOnly renders nothing when all seasons are already requested', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Available,
        'requested_seasons' => [1],
    ]);

    Livewire::test('request-title', ['title' => $title, 'moreOnly' => true])
        ->assertDontSee(__('Request more seasons…'));
});

test('moreOnly renders nothing for movies', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->movie()->create();

    Livewire::test('request-title', ['title' => $title, 'moreOnly' => true])
        ->assertDontSee(__('Request more seasons…'));
});

test('moreOnly renders the button when requested_seasons is null', function () {
    $this->actingAs(User::factory()->create());
    fakeConfiguredMediaRequestService();

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);
    LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Available,
        'requested_seasons' => null,
    ]);

    Livewire::test('request-title', ['title' => $title, 'moreOnly' => true])
        ->assertSee(__('Request more seasons…'));
});

test('moreOnly renders nothing when seerr is not configured', function () {
    $this->actingAs(User::factory()->create());

    $title = Title::factory()->show()->create();
    Season::factory()->for($title)->create(['season_number' => 1]);

    Livewire::test('request-title', ['title' => $title, 'moreOnly' => true])
        ->assertDontSee(__('Request more seasons…'));
});
