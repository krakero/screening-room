<?php

namespace App\Jobs;

use App\Actions\Ratings\RefreshExternalRatings;
use App\Models\Title;
use App\Services\MdbList\MdbListException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshTitleRatings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * Seconds to wait before retrying after MDBList answers 429.
     */
    public const RATE_LIMIT_BACKOFF = 900;

    public int $tries = 5;

    public function __construct(public readonly Title $title) {}

    public function uniqueId(): string
    {
        return (string) $this->title->id;
    }

    public function handle(RefreshExternalRatings $refreshExternalRatings): void
    {
        try {
            $refreshExternalRatings->handle($this->title, throwOnRateLimit: true);
        } catch (MdbListException $exception) {
            if (! $exception->isRateLimited()) {
                throw $exception;
            }

            $this->release(self::RATE_LIMIT_BACKOFF);
        }
    }
}
