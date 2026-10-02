<?php

namespace App\Jobs;

use App\Actions\Tmdb\RefreshSeasonTrailer as RefreshSeasonTrailerAction;
use App\Models\Season;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshSeasonTrailer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly Season $season) {}

    public function uniqueId(): string
    {
        return (string) $this->season->id;
    }

    public function handle(RefreshSeasonTrailerAction $refreshSeasonTrailer): void
    {
        $refreshSeasonTrailer->handle($this->season);
    }
}
