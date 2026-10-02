<?php

namespace App\Actions\Requests;

use App\Enums\LibraryState;
use App\Models\LibraryStatus;
use App\Models\Title;
use App\Services\MediaRequests\MediaRequestService;

class RequestTitle
{
    public function __construct(private MediaRequestService $service) {}

    /**
     * @param  array<int, int>  $seasonNumbers  Ignored for movies.
     */
    public function handle(Title $title, array $seasonNumbers = []): LibraryStatus
    {
        $existing = $this->service->mediaStatus($title);

        if ($existing !== null) {
            return LibraryStatus::updateOrCreate(
                ['title_id' => $title->id],
                [
                    'state' => $existing['available'] ? LibraryState::Available : LibraryState::Requested,
                    'seerr_request_id' => $existing['request_id'],
                    'seerr_status' => $existing['available'] ? null : $existing['seerr_status'],
                ],
            );
        }

        $response = $title->isMovie()
            ? $this->service->requestMovie($title)
            : $this->service->requestShow($title, $seasonNumbers);

        return LibraryStatus::updateOrCreate(
            ['title_id' => $title->id],
            [
                'state' => LibraryState::Requested,
                'seerr_request_id' => $response['request_id'],
                'seerr_status' => $response['seerr_status'],
            ],
        );
    }
}
