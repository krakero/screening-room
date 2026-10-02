<?php

namespace App\Services\MediaRequests;

use App\Enums\SeerrRequestStatus;
use App\Models\Title;

/**
 * Issues requests for media to be added to the library. Bound to an Seerr
 * driver today; a Seerr driver can be swapped in later without touching callers.
 */
interface MediaRequestService
{
    public function testConnection(): bool;

    /**
     * @return array{request_id: int|null, seerr_status: SeerrRequestStatus|null}
     */
    public function requestMovie(Title $title): array;

    /**
     * @param  array<int, int>  $seasonNumbers
     * @return array{request_id: int|null, seerr_status: SeerrRequestStatus|null}
     */
    public function requestShow(Title $title, array $seasonNumbers): array;

    /**
     * The title's current status on the media server, read directly from Seerr so a
     * request already made outside this app (or already available) isn't duplicated.
     * Returns null when Seerr has no record of the title at all.
     *
     * @return array{available: bool, request_id: int|null, seerr_status: SeerrRequestStatus|null, declined: bool}|null
     */
    public function mediaStatus(Title $title): ?array;
}
