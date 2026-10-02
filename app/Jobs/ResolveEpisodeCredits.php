<?php

namespace App\Jobs;

use App\Actions\Tmdb\FetchEpisodeCredits;
use App\Models\Episode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The only place an episode's TMDB credits are fetched live — dispatched instead of calling
 * TMDB during a request whenever nothing is cached yet under `FetchEpisodeCredits::cacheKey()`.
 */
class ResolveEpisodeCredits implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly Episode $episode) {}

    public function uniqueId(): string
    {
        return (string) $this->episode->id;
    }

    public function handle(FetchEpisodeCredits $fetch): void
    {
        $fetch->handle($this->episode);
    }
}
