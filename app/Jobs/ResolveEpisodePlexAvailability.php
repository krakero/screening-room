<?php

namespace App\Jobs;

use App\Actions\Plex\ResolvePlexAvailability;
use App\Models\Episode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The only place an episode's Plex availability is resolved live — dispatched instead of
 * calling Plex during a page render (EpisodeFlyout, Title/Season pages, the API) whenever no
 * `plex_items` row exists yet for the episode.
 */
class ResolveEpisodePlexAvailability implements ShouldBeUnique, ShouldQueue
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

    public function handle(ResolvePlexAvailability $resolver): void
    {
        $resolver->refreshEpisode($this->episode);
    }
}
