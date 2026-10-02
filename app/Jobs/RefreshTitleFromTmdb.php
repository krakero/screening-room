<?php

namespace App\Jobs;

use App\Actions\Tmdb\ImportTitle as ImportTitleAction;
use App\Models\Title;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Force-refreshes a single title from TMDB regardless of staleness: re-imports the title
 * itself and (for shows) re-queues an `ImportSeasonEpisodes` job for every season, which
 * overwrites existing episode rows — including placeholder names and missing stills —
 * with whatever TMDB has now. Dispatched from the "Refresh from TMDB" UI action, the API's
 * `POST /titles/{title}/refresh`, and `tmdb:refresh --title=`.
 */
class RefreshTitleFromTmdb implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $titleId) {}

    public function uniqueId(): string
    {
        return (string) $this->titleId;
    }

    public function handle(ImportTitleAction $importTitle): void
    {
        $title = Title::findOrFail($this->titleId);

        $importTitle->handle($title->type, $title->tmdb_id);
    }
}
