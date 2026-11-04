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

        if ($title->isMovie()) {
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

            $response = $this->service->requestMovie($title);

            return LibraryStatus::updateOrCreate(
                ['title_id' => $title->id],
                [
                    'state' => LibraryState::Requested,
                    'seerr_request_id' => $response['request_id'],
                    'seerr_status' => $response['seerr_status'],
                ],
            );
        }

        // Show
        $known = $existing['seasons'] ?? [];
        $toRequest = array_values(array_diff($seasonNumbers, $known));

        if ($existing !== null && $toRequest === []) {
            return LibraryStatus::updateOrCreate(
                ['title_id' => $title->id],
                [
                    'state' => $existing['available'] ? LibraryState::Available : LibraryState::Requested,
                    'seerr_request_id' => $existing['request_id'],
                    'seerr_status' => $existing['available'] ? null : $existing['seerr_status'],
                    'requested_seasons' => $known,
                ],
            );
        }

        $response = $this->service->requestShow($title, $toRequest);
        $allSeasons = array_values(array_unique(array_merge($known, $toRequest)));
        sort($allSeasons);

        return LibraryStatus::updateOrCreate(
            ['title_id' => $title->id],
            [
                'state' => LibraryState::Requested,
                'seerr_request_id' => $response['request_id'],
                'seerr_status' => $response['seerr_status'],
                'requested_seasons' => $allSeasons,
            ],
        );
    }

    /**
     * Refreshes the stored `requested_seasons` from Seerr for a show.
     * If no library status row exists yet, also seeds state from Seerr.
     * Returns the seasons array, or null when Seerr is unavailable or the title is a movie.
     *
     * @return array<int>|null
     */
    public function refreshSeasons(Title $title): ?array
    {
        $existing = $this->service->mediaStatus($title);

        if ($existing === null || $title->isMovie()) {
            return null;
        }

        $seasons = $existing['seasons'] ?? [];
        $status = LibraryStatus::where('title_id', $title->id)->first();

        if ($status) {
            $status->update(['requested_seasons' => $seasons]);
        } else {
            LibraryStatus::create([
                'title_id' => $title->id,
                'state' => $existing['available'] ? LibraryState::Available : LibraryState::Requested,
                'seerr_request_id' => $existing['request_id'],
                'seerr_status' => $existing['available'] ? null : $existing['seerr_status'],
                'requested_seasons' => $seasons,
            ]);
        }

        return $seasons;
    }
}
