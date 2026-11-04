<?php

use App\Actions\Requests\RequestTitle;
use App\Enums\LibraryState;
use App\Models\LibraryStatus;
use App\Models\Title;
use App\Services\MediaRequests\MediaRequestService;

function fakeMediaRequestService(int $requestId = 42): void
{
    app()->instance(MediaRequestService::class, new class($requestId) implements MediaRequestService
    {
        public function __construct(private int $requestId) {}

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
            return null;
        }
    });
}

/**
 * Returns a fake MediaRequestService that reports the given seasons already known by Seerr
 * and records which season numbers were passed to requestShow.
 *
 * @param  array<int>  $knownSeasons
 * @param  array<int>|null  $capturedSeasons  Reference populated on requestShow call.
 */
function fakeMediaRequestServiceWithSeasons(array $knownSeasons, int $requestId = 42, ?array &$capturedSeasons = null): void
{
    app()->instance(MediaRequestService::class, new class($knownSeasons, $requestId, $capturedSeasons) implements MediaRequestService
    {
        /** @param  array<int>  $knownSeasons */
        public function __construct(
            private array $knownSeasons,
            private int $requestId,
            private mixed &$capturedSeasons,
        ) {}

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
            $this->capturedSeasons = $seasonNumbers;

            return ['request_id' => $this->requestId, 'seerr_status' => null];
        }

        public function mediaStatus(Title $title): ?array
        {
            return [
                'available' => false,
                'request_id' => $this->requestId,
                'seerr_status' => null,
                'declined' => false,
                'seasons' => $this->knownSeasons,
            ];
        }
    });
}

test('requesting a movie creates a requested library status', function () {
    fakeMediaRequestService(42);

    $title = Title::factory()->movie()->create();

    $status = app(RequestTitle::class)->handle($title);

    expect($status->title_id)->toBe($title->id)
        ->and($status->state)->toBe(LibraryState::Requested)
        ->and($status->seerr_request_id)->toBe(42);
});

test('requesting a show again updates the existing status instead of duplicating', function () {
    fakeMediaRequestService(99);

    $title = Title::factory()->show()->create();

    app(RequestTitle::class)->handle($title, [1]);
    $status = app(RequestTitle::class)->handle($title, [1, 2]);

    expect(LibraryStatus::where('title_id', $title->id)->count())->toBe(1)
        ->and($status->seerr_request_id)->toBe(99);
});

test('handle() for shows only requests seasons not already known', function () {
    $captured = null;
    fakeMediaRequestServiceWithSeasons([1], 55, $captured);

    $title = Title::factory()->show()->create();

    app(RequestTitle::class)->handle($title, [1, 2, 3]);

    expect($captured)->toBe([2, 3]);
});

test('handle() for shows with nothing new syncs state without requesting', function () {
    $captured = null;
    fakeMediaRequestServiceWithSeasons([1, 2], 55, $captured);

    $title = Title::factory()->show()->create();

    $status = app(RequestTitle::class)->handle($title, [1, 2]);

    expect($captured)->toBeNull()
        ->and($status->requested_seasons)->toBe([1, 2]);
});

test('handle() writes requested_seasons as sorted union of known and newly requested seasons', function () {
    fakeMediaRequestServiceWithSeasons([1]);

    $title = Title::factory()->show()->create();

    $status = app(RequestTitle::class)->handle($title, [1, 3, 2]);

    expect($status->requested_seasons)->toBe([1, 2, 3]);
});

test('handle() writes requested_seasons when requesting all new seasons', function () {
    fakeMediaRequestServiceWithSeasons([]);

    $title = Title::factory()->show()->create();

    $status = app(RequestTitle::class)->handle($title, [2, 1]);

    expect($status->requested_seasons)->toBe([1, 2]);
});

test('refreshSeasons() returns seasons from mediaStatus and updates existing row', function () {
    fakeMediaRequestServiceWithSeasons([1, 2]);

    $title = Title::factory()->show()->create();
    $libraryStatus = LibraryStatus::factory()->for($title)->create(['requested_seasons' => null]);

    $result = app(RequestTitle::class)->refreshSeasons($title);

    expect($result)->toBe([1, 2])
        ->and($libraryStatus->fresh()->requested_seasons)->toBe([1, 2]);
});

test('refreshSeasons() only writes requested_seasons to an existing row', function () {
    fakeMediaRequestServiceWithSeasons([3]);

    $title = Title::factory()->show()->create();
    $libraryStatus = LibraryStatus::factory()->for($title)->create([
        'state' => LibraryState::Available,
        'requested_seasons' => null,
    ]);

    app(RequestTitle::class)->refreshSeasons($title);

    $fresh = $libraryStatus->fresh();
    expect($fresh->state)->toBe(LibraryState::Available)
        ->and($fresh->requested_seasons)->toBe([3]);
});

test('refreshSeasons() creates a row with state when no row exists', function () {
    fakeMediaRequestServiceWithSeasons([1, 2], 77);

    $title = Title::factory()->show()->create();

    $result = app(RequestTitle::class)->refreshSeasons($title);

    $status = LibraryStatus::where('title_id', $title->id)->sole();
    expect($result)->toBe([1, 2])
        ->and($status->state)->toBe(LibraryState::Requested)
        ->and($status->seerr_request_id)->toBe(77)
        ->and($status->requested_seasons)->toBe([1, 2]);
});

test('refreshSeasons() returns null for movies', function () {
    fakeMediaRequestServiceWithSeasons([]);

    $title = Title::factory()->movie()->create();

    expect(app(RequestTitle::class)->refreshSeasons($title))->toBeNull();
});

test('refreshSeasons() returns null when mediaStatus returns null', function () {
    fakeMediaRequestService();

    $title = Title::factory()->show()->create();

    expect(app(RequestTitle::class)->refreshSeasons($title))->toBeNull();
});
