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
